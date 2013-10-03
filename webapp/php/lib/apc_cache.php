<?php
function apc_cache_get($key)
{
    $now = microtime(true);
	$expire = (float)apc_fetch("$key#expire");
    if($expire>$now){
        return apc_fetch("$key#data");
    }
    return null;
}

function apc_cache_set($key,$data,$expire)
{
    $now = microtime(true);
    apc_store("$key#data",$data);
    apc_store("$key#expire",$now+$expire);
}
