# Giveaway Plugin for SunflowerFuchs/discord-bot

A plugin for [sunflowerfuchs/discord-bot]()

## Installation

## Usage

The following snippet is all that is required to add the plugin to your existing bot:

```php
$bot = new SunflowerFuchs\DiscordBot\Bot($options);

// This is the only relevant line
$bot->registerPlugin(new SunflowerFuchs\DiscordBot\Plugins\GiveawayPlugin());

$bot->run();
```

<!--
TODO: 
- add Contribution Guidelines
-->