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

    $sql = 'select ticket_id as id,ticket_name as name,sum(vacancy) as count,artist_id,artist_name from variation where artist_id=? group by ticket_id';
    $stmt = $db->prepare($sql);
    $stmt->execute(array($id));
    $tickets = $stmt->fetchAll();

    $artist = array(
        'id' => $tickets[0]['artist_id'],
        'name' => $tickets[0]['artist_name'],
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

    foreach ($variations as &$variation) {
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
    $db = option('db_conn');
    $db->beginTransaction();

    $variation_id = $_POST['variation_id'];
    $member_id = $_POST['member_id'];

    $stmt = $db->prepare('SELECT * FROM variation WHERE id=? FOR UPDATE');
    $stmt->execute(array($variation_id));
    $variation = $stmt->fetch(PDO::FETCH_ASSOC);
    if($variation['vacancy']<=0){
        $db->rollback();
        return html('soldout.html.php');
    }

	$sql = 'SELECT * FROM seat_random_list WHERE variation_id=:variation_id AND num=:num';
    $stmt = $db->prepare($sql);
    $stmt->execute(array(
       ':variation_id' => $variation_id,
       ':num' => $variation['vacancy']));
	$randomseat = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('INSERT INTO order_request (member_id,seat_id,v_name,t_name,a_name) VALUES (:id,:seat_id,:v_name,:t_name,:a_name)');
    $stmt->bindValue(':id', $member_id);
    $stmt->bindValue(':seat_id', $randomseat['seat_id']);
    $stmt->bindValue(':v_name', $variation['name']);
    $stmt->bindValue(':t_name', $variation['ticket_name']);
    $stmt->bindValue(':a_name', $variation['artist_name']);
    $stmt->execute();
    $order_id = $db->lastInsertId();

    $stmt = $db->prepare('UPDATE stock SET order_id = :order_id WHERE id=:id');
    $stmt->execute(array(
        ':id' => $randomseat['stock_id'],
        ':order_id' => $order_id,
    ));

    $stmt = $db->prepare('UPDATE variation SET vacancy = vacancy -1 WHERE id=?');
    $stmt->execute(array($variation_id));

    $db->commit();
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
    redirect_to('/admin');
});

dispatch('/admin/order.csv', function () {
    $db = option('db_conn');
    $stmt = $db->query(<<<SQL
SELECT order_request.*, stock.seat_id, stock.variation_id, stock.updated_at
FROM order_request JOIN stock ON order_request.id = stock.order_id
ORDER BY order_request.id ASC
SQL
);
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
