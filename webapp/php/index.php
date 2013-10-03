<?php
require_once('lib/redis.php');

if (php_sapi_name() === 'cli-server') {
    if (preg_match('/\.(?:png|jpg|jpeg|gif)$/', $_SERVER['REQUEST_URI'])) {
        return false;
    }
}

require_once 'lib/limonade.php';

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
        $cache = redis_cache_get($cachekey);
        if($cache){
            $rows = json_decode($cache,true);
        }
        else{
            $sql = 'SELECT * FROM order_request ORDER BY id DESC LIMIT 10';
            $db = option('db_conn');
            $stmt = $db->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            redis_cache_set($cachekey,json_encode($rows),0.7);
        }
        set('recent_sold', $rows);
    }
}

function after($output)
{
	redis_close_if_open();
	return $output;
}

dispatch('/', function () {
    $cachekey = "page_cache_/";
    $cache = redis_cache_get($cachekey);
    if($cache){
        return $cache;
    }

    $db = option('db_conn');
    $stmt = $db->query('SELECT * FROM artist ORDER BY id');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    set('artists', $rows);

    $page =  html('index.html.php');
    redis_cache_set($cachekey,$page,0.7);
    return $page;
});

dispatch('/artist/:id', function() {
    $id = params('id');

    $cachekey = "page_cache_artist:$id";
    $cache = redis_cache_get($cachekey);
    if($cache){
        return $cache;
    }

    $db = option('db_conn');

	$sql = 'select * from variation where artist_id=?';
    $stmt = $db->prepare($sql);
    $stmt->execute(array($id));
    $variations = $stmt->fetchAll();

    $redis = get_redis();
    $tickets = array();
    foreach($variations as $v){
        if(!isset($tickets[$v['ticket_id']])){
            $tickets[$v['ticket_id']]['id'] = $v['ticket_id'];
            $tickets[$v['ticket_id']]['name'] = $v['ticket_name'];
            $tickets[$v['ticket_id']]['count'] = 0;
        }
        $sales_count = (int)$redis->get("sales_count:{$v['id']}");
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
    redis_cache_set($cachekey,$page,0.7);
    return $page;
});

dispatch('/ticket/:id', function() {
    $ticket_id = params('id');

    $cachekey = "page_cache_ticket:$ticket_id";
    $cache = redis_cache_get($cachekey);
    if($cache){
        return $cache;
    }

    $db = option('db_conn');

    $stmt = $db->prepare('SELECT * FROM variation WHERE ticket_id = ?');
    $stmt->execute(array($ticket_id));
    $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ticket = array(
        'id' => $variations[0]['ticket_id'],
        'name' => $variations[0]['ticket_name'],
        'artist_id' => $variations[0]['artist_id'],
        'artist_name' => $variations[0]['artist_name'],
    );

	$redis = get_redis();

    foreach ($variations as &$variation) {
		$sales_count = (int)$redis->get("sales_count:{$variation['id']}");
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

    redis_cache_set($cachekey,$page,0.7);
    return $page;
});

dispatch_post('/buy', function() {

    $variation_id = $_POST['variation_id'];
    $member_id = $_POST['member_id'];

	$redis = get_redis();
	$sales_count = $redis->incr("sales_count:$variation_id");

    $db = option('db_conn');

    $stmt = $db->prepare('SELECT * FROM variation WHERE id=?');
    $stmt->execute(array($variation_id));
    $variation = $stmt->fetch(PDO::FETCH_ASSOC);

    if($variation['total_seat'] < $sales_count){
        return html('soldout.html.php');
    }

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

    $redis = new Redis();
    $redis->connect("127.0.0.1",6379);
	$keys = $redis->keys('sales_count:*');
	$redis->del($keys);

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
