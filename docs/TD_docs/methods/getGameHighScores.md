---
title: getGameHighScores
description: Bots only. Returns game high scores and some part of the score table around of the specified user in the game
---
## Method: getGameHighScores  
[Back to methods index](index.md)


Bots only. Returns game high scores and some part of the score table around of the specified user in the game

### Params:

| Name     |    Type       | Required | Description |
|----------|:-------------:|:--------:|------------:|
|chat\_id|[long](../types/long.md) | Yes|Chat a message with the game belongs to|
|message\_id|[long](../types/long.md) | Yes|Identifier of the message|
|user\_id|[int](../types/int.md) | Yes|User identifie|


### Return type: [GameHighScores](../types/GameHighScores.md)

### Example:


```
$MadelineProto = new \danog\MadelineProto\API();
if (isset($token)) {
    $this->bot_login($token);
}
if (isset($number)) {
    $sentCode = $MadelineProto->phone_login($number);
    echo 'Enter the code you received: ';
    $code = '';
    for ($x = 0; $x < $sentCode['type']['length']; $x++) {
        $code .= fgetc(STDIN);
    }
    $MadelineProto->complete_phone_login($code);
}

$GameHighScores = $MadelineProto->getGameHighScores(['chat_id' => long, 'message_id' => long, 'user_id' => int, ]);
```

Or, if you're into Lua:

```
GameHighScores = getGameHighScores({chat_id=long, message_id=long, user_id=int, })
```

