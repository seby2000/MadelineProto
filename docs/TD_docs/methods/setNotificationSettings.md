---
title: setNotificationSettings
description: Changes notification settings for a given scope
---
## Method: setNotificationSettings  
[Back to methods index](index.md)


Changes notification settings for a given scope

### Params:

| Name     |    Type       | Required | Description |
|----------|:-------------:|:--------:|------------:|
|scope|[NotificationSettingsScope](../types/NotificationSettingsScope.md) | Yes|Scope to change notification settings|
|notification\_settings|[notificationSettings](../types/notificationSettings.md) | Yes|New notification settings for given scope|


### Return type: [Ok](../types/Ok.md)

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

$Ok = $MadelineProto->setNotificationSettings(['scope' => NotificationSettingsScope, 'notification_settings' => notificationSettings, ]);
```

Or, if you're into Lua:

```
Ok = setNotificationSettings({scope=NotificationSettingsScope, notification_settings=notificationSettings, })
```

