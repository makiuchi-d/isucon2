<?php

function get_redis()
{
    static $redis = null;
    if($redis){
        return $redis;
    }
    $redis = new Redis();
    $redis->connect("127.0.0.1",6379);
    return $redis;
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
