<?php

$redis = null;

function get_redis()
{
    global $redis;
    if($redis){
        return $redis;
    }
    $redis = new Redis();
    $redis->connect("127.0.0.1",16379);
    return $redis;
}

function redis_close_if_open()
{
	global $redis;
	if($redis){
		$redis->close();
	}
}

function redis_cache_get($key)
{
    $now = microtime(true);
    $redis = get_redis();
    $expire = (float)$redis->get("$key#expire");
    if($expire>$now){
        return $redis->get("$key#data");
    }
    return null;
}

function redis_cache_set($key,$data,$expire)
{
    $now = microtime(true);
    $redis = get_redis();
    $redis->set("$key#data",$data);
    $redis->set("$key#expire",$now+$expire);
}
