<?php

namespace SunflowerFuchs\DiscordBot\GiveawayPlugin;

use Exception;
use PDO;
use Pecee\Pixie\Connection;
use Pecee\Pixie\QueryBuilder\QueryBuilderHandler;
use React\EventLoop\TimerInterface;
use SunflowerFuchs\DiscordBot\Api\Constants\Events;
use SunflowerFuchs\DiscordBot\Api\Objects\AllowedMentions;
use SunflowerFuchs\DiscordBot\Api\Objects\Emoji;
use SunflowerFuchs\DiscordBot\Api\Objects\Message;
use SunflowerFuchs\DiscordBot\Api\Objects\Reaction;
use SunflowerFuchs\DiscordBot\Api\Objects\Snowflake;
use SunflowerFuchs\DiscordBot\Plugins\BasePlugin;

class GiveawayPlugin extends BasePlugin
{
    protected const TABLE = 'giveaways';
    protected const COL_ID = 'id';
    protected const COL_DRAWING_DATE = 'drawing';
    protected const COL_WINNINGS = 'winnings';
    protected const COL_EMOJI = 'emoji';
    protected const COL_GUILD_ID = 'guildId';
    protected const COL_CHANNEL_ID = 'channelId';
    protected const COL_MESSAGE_ID = 'messageId';
    protected const RETRY_DELAY = 30;
    protected const MAX_RETRIES = 3;

    protected QueryBuilderHandler $db;
    protected array $cache = [];
    protected bool $hydrated = false;
    protected ?TimerInterface $timer = null;

    public function init()
    {
        $this->db = $this->initDatabase();
        $this->getBot()->registerCommand('giveaway', [$this, 'parseCommand']);
        $this->subscribeToEvent(Events::MESSAGE_DELETE, [$this, 'handleDeletedMessage']);
    }

    public function ready()
    {
        parent::ready();
        $this->prepareNextGiveaway();
    }

    public function parseCommand(Message $message): bool
    {
        if (!$this->getBot()->getPermissionManager()->isAdmin($message->getGuildId(), $message->getMember())) {
            return true;
        }

        $params = $message->getCommandParams($this->getBot()->getPrefix());
        switch ($params[1] ?? '') {
            case 'start':
                return $this->askForTime($message);
            case 'stop':
                return $this->selectGiveawayToDelete($message);
            case 'list':
                return $this->listGiveaways($message);
            default:
                $subcommands = ['start', 'stop', 'list'];
                $this->sendMessage("Invalid subcommand; Known subcommands:\n " . implode("\n ", $subcommands),
                    $message->getChannelId());
                return true;
        }
    }

    protected function askForTime(Message $message): bool
    {
        $this->sendMessageAndAwaitResponse(
            'What date/time do you want the giveaway to end?',
            $message->getChannelId(),
            $message->getAuthor()->getId(),
            [$this, 'parseTime']
        );

        return true;
    }

    protected function parseTime(Message $message): bool
    {
        $time = strtotime($message->getContent(), $message->getTimestamp());
        if (!$time) {
            $this->sendMessage(
                'I could not understand that time. Try something like "15 July 2022 4:33 PM" or "midnight next thursday"',
                $message->getChannelId()
            );
            return false;
        }

        $this->sendMessageAndAwaitResponse(
            "Is <t:${time}> correct?",
            $message->getChannelId(),
            $message->getAuthor()->getId(),
            fn(Message $message) => $this->confirmTime($message, $time)
        );

        return true;
    }

    protected function confirmTime(Message $message, int $drawing): bool
    {
        // If the answer isn't just yes, restart from the beginning
        if (strtolower($message->getContent()) !== 'yes') {
            return $this->askForTime($message);
        }

        $this->sendMessageAndAwaitResponse(
            'What are you giving away?',
            $message->getChannelId(),
            $message->getAuthor()->getId(),
            fn(Message $message) => $this->parseWinnings($message, $drawing)
        );
        return true;
    }

    protected function parseWinnings(Message $message, int $drawing): bool
    {
        $winnings = $message->getContent();
        $this->sendMessageAndAwaitResponse(
            'What channel should i announce the giveaway and winners in?',
            $message->getChannelId(),
            $message->getAuthor()->getId(),
            fn(Message $message) => $this->parseChannel($message, $drawing, $winnings)
        );
        return true;
    }

    protected function parseChannel(Message $message, int $drawing, string $winnings): bool
    {
        $success = preg_match('/^<#(\d+)>$/', $message->getContent(), $matches);
        if (!$success) {
            $this->sendMessage(
                'Please send the channel i should announce the giveaway/winners in',
                $message->getChannelId()
            );
        }
        $channelId = new Snowflake($matches[1]);

        $this->sendMessageAndAwaitResponse(
            'What emoji should people react with?',
            $message->getChannelId(),
            $message->getAuthor()->getId(),
            fn(Message $message) => $this->parseEmoji($message, $drawing, $winnings, $channelId)
        );
        return true;
    }

    protected function parseEmoji(Message $message, int $drawing, string $winnings, Snowflake $channelId): bool
    {
        $content = $message->getContent();
        if (Emoji::isStandardEmoji($content)) {
            $emojiString = $content;
        } else {
            $isEmoji = preg_match('/^<a?:(\w+):(\d+)>$/', $content, $matches);
            if (!$isEmoji) {
                $this->sendMessage('Please send the emoji people should react with', $message->getChannelId());
                return false;
            }

            $emoji = Emoji::loadById(
                $this->getBot()->getApiClient(),
                $message->getGuildId(),
                new Snowflake($matches[2])
            );
            if ($emoji === null) {
                $this->sendMessage(
                    'Cannot use emoji. Has to bei either a default emoji, or a custom emoji from the current server.',
                    $message->getChannelId()
                );
                return false;
            }

            $emojiId = $emoji->getId();
            $emojiName = $emoji->getName();
            $animatedFlag = $emoji->isAnimated() ? 'a' : '';
            $emojiString = "<${animatedFlag}:${emojiName}:${emojiId}>";
        }

        $giveawayMessage = $this->announceGiveaway($drawing, $winnings, $emojiString, $channelId);
        if (!$giveawayMessage instanceof Message) {
            $this->sendMessage('An error occurred while announcing the giveaway, sorry', $message->getChannelId());
            return false;
        }

        $success = $this->storeGiveaway(
            $drawing,
            $winnings,
            $emojiString,
            $channelId,
            $message->getGuildId(),
            $giveawayMessage->getId()
        );
        if (!$success) {
            $this->sendMessage('An error occurred while saving the giveaway, sorry', $message->getChannelId());
            return false;
        }

        // And make sure we prepare for drawing a winner
        $this->prepareNextGiveaway();
        return true;
    }

    protected function announceGiveaway(int $drawing, string $winnings, string $emoji, Snowflake $channelId): ?Message
    {
        $giveawayMessage = <<<EOL
It's giveaway time! @everyone
We are giving away ${winnings}.
React to this message with ${emoji} to participate.
The winner will be drawn on <t:${drawing}>.
Good luck!
EOL;

        $allowedMention = (new AllowedMentions())->allowEveryone();
        $message = $this->sendMessage($giveawayMessage, $channelId, $allowedMention);
        if ($message instanceof Message) {
            if ($this->addReaction($emoji, $message->getId(), $channelId)) {
                return $message;
            }
        }

        return null;
    }

    protected function selectGiveawayToDelete(Message $message): bool
    {
        $giveaways = array_map(
            fn(array $giveaway) => sprintf(
                '%d: %s, <t:%d>, announced in <#%d>',
                $giveaway[self::COL_ID],
                $giveaway[self::COL_WINNINGS],
                $giveaway[self::COL_DRAWING_DATE],
                $giveaway[self::COL_CHANNEL_ID]),
            $this->getGiveaways($message->getGuildId()));
        if (empty($giveaways)) {
            $this->sendMessage('There are currently no giveaways running', $message->getChannelId());
            return true;
        }

        $this->sendMessageAndAwaitResponse(
            "Which giveaway do you want to delete?\n " . implode("\n ", $giveaways),
            $message->getChannelId(),
            $message->getAuthor()->getId(),
            function (Message $message) {
                $id = intval($message->getContent());
                $giveaways = $this->getGiveaways($message->getGuildId());
                if (!isset($giveaways[$id])) {
                    $this->sendMessage("Could not find giveaway", $message->getChannelId());
                    return true;
                }

                $success = $this->deleteGiveaway($id);
                Message::delete(
                    $this->getBot()->getApiClient(),
                    new Snowflake($giveaways[$id][self::COL_MESSAGE_ID]),
                    new Snowflake($giveaways[$id][self::COL_CHANNEL_ID])
                );
                $text = $success ? "Giveaway ${id} deleted" : 'Could not delete giveaway';
                $this->sendMessage($text, $message->getChannelId());
                return true;
            }
        );
        return true;
    }

    protected function listGiveaways(Message $message): bool
    {
        $giveaways = $this->getGiveaways($message->getGuildId());
        if (empty($giveaways)) {
            $this->sendMessage('No giveaways currently running.', $message->getChannelId());
            return true;
        }

        $prettyGiveaways = array_map(
            function (array $giveaway) use ($message) {
                return sprintf(
                    '%d: %s, <t:%d>, announced in <#%d> with %s',
                    $giveaway[self::COL_ID],
                    $giveaway[self::COL_WINNINGS],
                    $giveaway[self::COL_DRAWING_DATE],
                    $giveaway[self::COL_CHANNEL_ID],
                    $giveaway[self::COL_EMOJI]);
            }, $giveaways);

        $this->sendMessage(implode("\n", $prettyGiveaways), $message->getChannelId());
        return true;
    }

    public function handleDeletedMessage(array $data): bool
    {
        $messageId = new Snowflake($data['id']);
        $giveawayIds = $this->db
            ->table(self::TABLE)
            ->where(self::COL_MESSAGE_ID, '=', $messageId->toInt())
            ->select(self::COL_ID)
            ->setFetchMode(PDO::FETCH_ASSOC)
            ->limit(1)
            ->get();

        if (!empty($giveawayIds)) {
            $row = $giveawayIds[0];
            return $this->deleteGiveaway($row[self::COL_ID]);
        }

        return true;
    }

    protected function initDatabase(): QueryBuilderHandler
    {
        $db = (new Connection('sqlite', [
            'database' => $this->getDataDir() . 'giveaways.sqlite'
        ]))->getQueryBuilder();

        $db->query(sprintf(<<<'SQL'
CREATE TABLE IF NOT EXISTS
%s (
    %s INTEGER PRIMARY KEY AUTOINCREMENT,
    %s INTEGER NOT NULL,
    %s TEXT NOT NULL,
    %s TEXT NOT NULL,
    %s INTEGER NOT NULL,
    %s INTEGER NOT NULL,
    %s INTEGER NOT NULL
)
SQL,
                self::TABLE,
                self::COL_ID,
                self::COL_DRAWING_DATE,
                self::COL_WINNINGS,
                self::COL_EMOJI,
                self::COL_CHANNEL_ID,
                self::COL_GUILD_ID,
                self::COL_MESSAGE_ID)
        );

        return $db;
    }

    protected function storeGiveaway(
        int $drawing,
        string $winnings,
        string $emoji,
        Snowflake $channelId,
        Snowflake $guildId,
        Snowflake $messageId
    ): bool {
        try {
            $row = [
                self::COL_DRAWING_DATE => $drawing,
                self::COL_WINNINGS => $winnings,
                self::COL_EMOJI => $emoji,
                self::COL_CHANNEL_ID => $channelId->toInt(),
                self::COL_GUILD_ID => $guildId->toInt(),
                self::COL_MESSAGE_ID => $messageId->toInt()
            ];
            $id = (int)$this->db
                ->table(self::TABLE)
                ->insert($row);

            // Insert into cache, but only if cache was hydrated
            if (isset($this->cache[$guildId->toInt()])) {
                $row[self::COL_ID] = $id;
                $this->cache[$guildId->toInt()][$id] = $row;
            }
        } catch (Exception $e) {
            $this->getBot()->getLogger()->error('Unexpected exception', [$e]);
            return false;
        }

        return true;
    }

    protected function deleteGiveaway(int $giveawayId): bool
    {
        try {
            $this->db
                ->table(self::TABLE)
                ->where(self::COL_ID, '=', $giveawayId)
                ->delete();

            $this->deleteGiveawayFromCache($giveawayId);
        } catch (Exception $e) {
            $this->getBot()->getLogger()->error('Unexpected exception', [$e]);
            return false;
        }

        return true;
    }

    protected function delayGiveaway(int $giveawayId): bool
    {
        try {
            $giveaway = $this->getAllGiveaways()[$giveawayId] ?? null;
            if (!$giveaway) {
                throw new Exception("Could not find giveaway ${giveawayId}");
            }

            $newDate = $giveaway[self::COL_DRAWING_DATE] + (self::RETRY_DELAY * 10);

            $this->db
                ->table(self::TABLE)
                ->where(self::COL_ID, '=', $giveawayId)
                ->update([self::COL_DRAWING_DATE => $newDate]);

            $this->cache[$giveaway[self::COL_GUILD_ID]][$giveawayId][self::COL_DRAWING_DATE] = $newDate;
        } catch (Exception $e) {
            $this->getBot()->getLogger()->error('Unexpected exception', [$e]);
            return false;
        }

        return true;
    }

    protected function getGiveaways(Snowflake $guildId): array
    {
        if ($this->hydrated) {
            $this->hydrateCache();
        }

        return $this->cache[$guildId->toInt()];
    }

    protected function hydrateCache(): void
    {
        $this->cache = [];

        $res = $this->db
            ->table(self::TABLE)
            ->setFetchMode(PDO::FETCH_ASSOC)
            ->get();

        foreach ($res as $row) {
            $this->cache[$row[self::COL_GUILD_ID]] ??= [];
            $this->cache[$row[self::COL_GUILD_ID]][$row[self::COL_ID]] = $row;
        }
    }

    protected function deleteGiveawayFromCache(int $giveawayId): void
    {
        foreach ($this->cache as $guildId => $giveaways) {
            if (isset($giveaways[$giveawayId])) {
                unset($this->cache[$guildId][$giveawayId]);
                return;
            }
        }
    }

    protected function getAllGiveaways(): array
    {
        if (!$this->hydrated) {
            $this->hydrateCache();
        }

        // flatten the array
        return array_reduce($this->cache, fn(array $carry, array $giveaways) => $carry + $giveaways, []);
    }

    protected function prepareNextGiveaway(): void
    {
        if ($this->timer !== null) {
            $this->getBot()->getLoop()->cancelTimer($this->timer);
            $this->timer = null;
        }

        $giveaways = $this->getAllGiveaways();
        if (empty($giveaways)) {
            return;
        }

        usort(
            $giveaways,
            fn(array $row1, array $row2) => $row1[self::COL_DRAWING_DATE] <=> $row2[self::COL_DRAWING_DATE]
        );
        $next = reset($giveaways);

        $waitTime = $next[self::COL_DRAWING_DATE] - time();
        $this->timer = $this->getBot()->getLoop()->addTimer(
            $waitTime,
            fn() => $this->drawWinner($next[self::COL_ID])
        );
    }

    protected function drawWinner(int $giveawayId, int $retry = 0): void
    {
        $giveaway = $this->getAllGiveaways()[$giveawayId] ?? [];
        if (!$giveaway) {
            // giveaway doesn't exist anymore, just move on to the next one
            $this->prepareNextGiveaway();
            return;
        }

        $channelId = new Snowflake($giveaway[self::COL_CHANNEL_ID]);
        $messageId = new Snowflake($giveaway[self::COL_MESSAGE_ID]);
        $winnings = $giveaway[self::COL_WINNINGS];
        $emojiString = $giveaway[self::COL_EMOJI];
        $emoji = Emoji::normalizeEmoji($emojiString);

        $users = Reaction::getAllUsers(
            $this->getBot()->getApiClient(),
            $channelId,
            $messageId,
            $emoji
        );
        if ($users === null) {
            $retry++;
            if ($retry >= self::MAX_RETRIES) {
                $this->sendMessage("Could not draw winner for giveaway ${giveawayId}", $channelId);
                $this->getBot()->getLogger()->error("Could not draw winner for giveaway", $giveaway);
                $this->delayGiveaway($giveawayId);
                $this->prepareNextGiveaway();
                return;
            }

            $this->getBot()->getLoop()->addTimer(self::RETRY_DELAY, fn() => $this->drawWinner($giveawayId, $retry));
        }

        // Remove the bot from the user list
        if (isset($users[$this->getBot()->getUserId()->toInt()])) {
            unset($users[$this->getBot()->getUserId()->toInt()]);
        }

        if (empty($users)) {
            $this->sendMessage("It seems like nobody wanted to win ${winnings}, what a bummer.", $channelId);
            $this->deleteGiveaway($giveawayId);
            $this->prepareNextGiveaway();
            return;
        }

        $winnerId = new Snowflake(array_rand($users));
        $this->sendMessage(
            "Congratulations to <@${winnerId}> for winning ${winnings}",
            $channelId,
            (new AllowedMentions())->allowUser($winnerId)
        );

        $this->deleteGiveaway($giveawayId);
        $this->prepareNextGiveaway();
    }
}