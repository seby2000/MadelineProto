---
title: decryptedMessageMediaVideo
description: decryptedMessageMediaVideo attributes, type and example
---
## Constructor: decryptedMessageMediaVideo\_45  
[Back to constructors index](index.md)



### Attributes:

| Name     |    Type       | Required |
|----------|:-------------:|---------:|
|thumb|[bytes](../types/bytes.md) | Yes|
|thumb\_w|[int](../types/int.md) | Yes|
|thumb\_h|[int](../types/int.md) | Yes|
|duration|[int](../types/int.md) | Yes|
|mime\_type|[string](../types/string.md) | Yes|
|w|[int](../types/int.md) | Yes|
|h|[int](../types/int.md) | Yes|
|size|[int](../types/int.md) | Yes|
|key|[bytes](../types/bytes.md) | Yes|
|iv|[bytes](../types/bytes.md) | Yes|
|caption|[string](../types/string.md) | Yes|



### Type: [DecryptedMessageMedia](../types/DecryptedMessageMedia.md)


### Example:

```
$decryptedMessageMediaVideo_45 = ['_' => 'decryptedMessageMediaVideo', 'thumb' => bytes, 'thumb_w' => int, 'thumb_h' => int, 'duration' => int, 'mime_type' => string, 'w' => int, 'h' => int, 'size' => int, 'key' => bytes, 'iv' => bytes, 'caption' => string, ];
```  

Or, if you're into Lua:  


```
decryptedMessageMediaVideo_45={_='decryptedMessageMediaVideo', thumb=bytes, thumb_w=int, thumb_h=int, duration=int, mime_type=string, w=int, h=int, size=int, key=bytes, iv=bytes, caption=string, }

```


