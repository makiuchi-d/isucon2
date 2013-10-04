<?php

if (php_sapi_name() === 'cli-server') {
    if (preg_match('/\.(?:png|jpg|jpeg|gif)$/', $_SERVER['REQUEST_URI'])) {
        return false;
    }
}

require_once 'lib/limonade.php';

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

function get_variations($conditions=array())
{
    $variations = apc_fetch('variations_cache');
    if(!$variations){
        $db = option('db_conn');
        $stmt = $db->query('SELECT * FROM variation');
        $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        apc_store('variations_cache',$variations);
    }
    foreach($conditions as $k=>$v){
        $tmp = array();
        foreach($variations as $var){
            if($var[$k]==$v){
                $tmp[] = $var;
            }
        }
        $variations = $tmp;
    }
    return $variations;
}


function configure()
{
    option('base_uri', '');
    option('session', false);

    $env = getenv('ISUCON_ENV');
    if (! $env) $env = 'local';

    $file = realpath(__DIR__ . '/../config/common.' . $env . '.json');
    $fh = fopen($file, 'r');
    $config = json_decode(fread($fh, filesize($file)), true);
    fclose($fh);

    $db = null;
    try {
        $db = new PDO(
            'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
            $config['database']['username'],
            $config['database']['password'],
            array(
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
            )
        );
    } catch (PDOException $e) {
        halt("Connection faild: $e");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    option('db_conn', $db);
}

function before()
{
    layout('layout.html.php');
    $path = option('base_path');
    if ('/' === $path || preg_match('#^/(?:artist|ticket)#', $path)) {
        $cachekey = "recent_orders";
        $cache = apc_cache_get($cachekey);
        if($cache){
            $rows = json_decode($cache,true);
        }
        else{
            $sql = 'SELECT * FROM order_request ORDER BY id DESC LIMIT 10';
            $db = option('db_conn');
            $stmt = $db->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            apc_cache_set($cachekey,json_encode($rows),0.7);
        }
        set('recent_sold', $rows);
    }
}

dispatch('/', function () {
    $cachekey = "page_cache_/";
    $cache = apc_cache_get($cachekey);
    if($cache){
        return $cache;
    }

    $db = option('db_conn');
    $stmt = $db->query('SELECT * FROM artist ORDER BY id');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    set('artists', $rows);

    $page =  html('index.html.php');
    apc_cache_set($cachekey,$page,0.7);
    return $page;
});

dispatch('/artist/:id', function() {
    $id = params('id');

    $cachekey = "page_cache_artist:$id";
    $cache = apc_cache_get($cachekey);
    if($cache){
        return $cache;
    }

    $db = option('db_conn');

    $variations = get_variations(array('artist_id'=>$id));

    $tickets = array();
    foreach($variations as $v){
        if(!isset($tickets[$v['ticket_id']])){
            $tickets[$v['ticket_id']]['id'] = $v['ticket_id'];
            $tickets[$v['ticket_id']]['name'] = $v['ticket_name'];
            $tickets[$v['ticket_id']]['count'] = 0;
        }
        $sales_count = (int)apc_fetch("sales_count:{$v['id']}");
        $tickets[$v['ticket_id']]['count'] += max(0,$v['total_seat']-$sales_count);
    }

    $v = array_pop($variations);
    $artist = array(
        'id' => $v['artist_id'],
        'name' => $v['artist_name'],
        );

    set('artist', $artist);
    set('tickets', $tickets);
    $page = html('artist.html.php');
    apc_cache_set($cachekey,$page,0.7);
    return $page;
});

dispatch('/ticket/:id', function() {
    $ticket_id = params('id');

    $cachekey = "page_cache_ticket:$ticket_id";
    $cache = apc_cache_get($cachekey);
    if($cache){
        return $cache;
    }

    $db = option('db_conn');

    $variations = get_variations(array('ticket_id'=>$ticket_id));
    $ticket = array(
        'id' => $variations[0]['ticket_id'],
        'name' => $variations[0]['ticket_name'],
        'artist_id' => $variations[0]['artist_id'],
        'artist_name' => $variations[0]['artist_name'],
    );

    foreach ($variations as &$variation) {
        $sales_count = (int)apc_fetch("sales_count:{$variation['id']}");
        $variation['vacancy'] = max(0,$variation['total_seat']-$sales_count);

        $variation['stock'] = array();
        $stmt = $db->prepare('SELECT seat_id, order_id FROM stock WHERE variation_id = :id');
        $stmt->bindValue(':id', $variation['id']);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $stock) {
            $variation['stock'][$stock['seat_id']] = $stock;
        }
    }

    set('ticket', $ticket);
    set('variations', $variations);

    $page = html('ticket.html.php');

    apc_cache_set($cachekey,$page,0.7);
    return $page;
});

dispatch_post('/buy', function() {

    $variation_id = $_POST['variation_id'];
    $member_id = $_POST['member_id'];

    $sales_count = apc_inc("sales_count:$variation_id");

    $variations = get_variations(array('id'=>$variation_id));
    $variation = $variations[0];

    if($variation['total_seat'] < $sales_count){
        return html('soldout.html.php');
    }

    $db = option('db_conn');

    $sql = 'SELECT * FROM seat_random_list WHERE variation_id=:variation_id AND num=:num';
    $stmt = $db->prepare($sql);
    $stmt->execute(array(
       ':variation_id' => $variation_id,
       ':num' => $sales_count));
    $randomseat = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('INSERT INTO order_request (member_id,seat_id,variation_id,v_name,t_name,a_name) VALUES (:id,:seat_id,:variation_id,:v_name,:t_name,:a_name)');
    $stmt->bindValue(':id', $member_id);
    $stmt->bindValue(':seat_id', $randomseat['seat_id']);
    $stmt->bindValue(':variation_id', $variation_id);
    $stmt->bindValue(':v_name', $variation['name']);
    $stmt->bindValue(':t_name', $variation['ticket_name']);
    $stmt->bindValue(':a_name', $variation['artist_name']);
    $stmt->execute();
    $order_id = $db->lastInsertId();

    $stmt = $db->prepare('UPDATE stock SET order_id = :order_id WHERE id=:id');
    $stmt->execute(array(':id'=>$randomseat['stock_id'],':order_id'=>$order_id));

    set('member_id', $member_id);
    set('seat_id', $randomseat['seat_id']);

    return html('complete.html.php');
});

dispatch('/admin', function () {
    return html('admin.html.php');
});

dispatch_post('/admin', function () {
    $db = option('db_conn');
    $fh = fopen(realpath(__DIR__ . '/../config/database/initial_data.sql'), 'r');
    while ($sql = fgets($fh)) {
        $sql = rtrim($sql);
        if (!empty($sql)) $db->exec($sql);
    }
    fclose($fh);

    apc_clear_cache('user');
    $variations = get_variations();
    foreach($variations as $v){
        apc_store("sales_count:{$v['id']}",0);
    }

    redirect_to('/admin');
});

dispatch('/admin/order.csv', function () {
    $db = option('db_conn');

    $stmt = $db->query("SELECT id,member_id,seat_id,variation_id,updated_at FROM order_request ORDER BY id ASC");

    $body = '';
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as &$order) {
        $body .= join(',', array($order['id'], $order['member_id'], $order['seat_id'], $order['variation_id'], $order['updated_at']));
        $body .= "\n";
    }

    send_header('Content-Type: text/csv');
    return $body;
});

run();
