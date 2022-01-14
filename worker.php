<?php

function get_database_connection()
{
    # database connection
    try {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=worker;charset=UTF8',
            'root',
            '',
            array(PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
    } catch (PDOException $e) {
        die('Error with DB!: ' . $e->getMessage() . '<br/>');
    }

    return $pdo;
}

function getAllUrl(PDO $db, $status = 'NEW'): bool|array
{
    $result = [];
    $sql = "SELECT * FROM sites WHERE status = :status";
    $stm = $db->prepare($sql);
    $stm->execute(
        [
            'status' => $status
        ]
    );

    if (!$stm->errorInfo()[1]) {
        $result = $stm->fetchAll(PDO::FETCH_OBJ);
    }

    return $result;
}

function getUrl(PDO $db, $id)
{
    $result = [];
    $sql = "SELECT url, status FROM sites WHERE id = :id AND status=:status FOR UPDATE";
    $stm = $db->prepare($sql);
    $stm->execute(
        [
            'id' => $id,
            'status' => 'NEW'
        ]
    );

    if (!$stm->errorInfo()[1]) {
        $result = $stm->fetch(PDO::FETCH_OBJ);
    }

    return $result;
}

function updateRow(PDO $db, $id, $worker = '', $status = 'PROCESSING', $http_code = 0): int
{
    $sql = 'UPDATE sites SET status = :status, http_code = :http_code, worker = :worker ';
    $sql .= 'WHERE id = :id';

    $stm = $db->prepare($sql);

    $stm->execute([
        'id' => $id,
        'status' => $status,
        'http_code' => $http_code,
        'worker' => $worker
    ]);

    return $stm->rowCount();

}

function isValidUrl(PDO $db, $id, $url): bool
{
    //change status to PROCESSING if not return false (occupate)
    if (!updateRow($db, $id)) {
        return -1;
    }

    // first do some quick sanity checks:
    if (!$url || !is_string($url)) {
        return false;
    }
    // quick check url is roughly a valid http request: ( http://blah/... )
    if (!preg_match('/^http(s)?:\/\/[a-z0-9-]+(\.[a-z0-9-]+)*(:[0-9]+)?(\/.*)?$/i', $url)) {
        return false;
    }
    // the next bit could be slow:
    $http_code = getHttpResponseCode_using_curl($url) ?? 0;
    if ($http_code != 200) {
//      if(getHttpResponseCode_using_getheaders($url) != 200){  // use this one if you cant use curl
        return false;
    }
    // all good!
    updateRow($db, $id, $GLOBALS['worker'], 'DONE', $http_code);
    return true;
}

function getHttpResponseCode_using_curl($url, $followredirects = true)
{
    // returns int responsecode, or false (if url does not exist or connection timeout occurs)
    // NOTE: could potentially take up to 0-30 seconds , blocking further code execution (more or less depending on connection, target site, and local timeout settings))
    // if $followredirects == false: return the FIRST known httpcode (ignore redirects)
    // if $followredirects == true : return the LAST  known httpcode (when redirected)
    if (!$url || !is_string($url)) {
        return false;
    }
    $ch = @curl_init($url);
    if ($ch === false) {
        return false;
    }
    @curl_setopt($ch, CURLOPT_HEADER, true);    // we want headers
    @curl_setopt($ch, CURLOPT_NOBODY, true);    // don't need body
    @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);    // catch output (do NOT print!)
    if ($followredirects) {
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        @curl_setopt($ch, CURLOPT_MAXREDIRS, 10);  // fairly random number, but could prevent unwanted endless redirects with followlocation=true
    } else {
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    }
    @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);   // fairly random number (seconds)... but could prevent waiting forever to get a result
    @curl_setopt($ch, CURLOPT_TIMEOUT, 6);   // fairly random number (seconds)... but could prevent waiting forever to get a result
//      @curl_setopt($ch, CURLOPT_USERAGENT      ,"Mozilla/5.0 (Windows NT 6.0) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1");   // pretend we're a regular browser
    @curl_exec($ch);
    if (@curl_errno($ch)) {   // should be 0
        @curl_close($ch);
        return false;
    }
    $code = @curl_getinfo($ch, CURLINFO_HTTP_CODE); // note: php.net documentation shows this returns a string, but really it returns an int
    @curl_close($ch);
    return $code;
}

function getHttpResponseCode_using_getheaders($url, $followredirects = false)
{
    // returns string responsecode, or false if no responsecode found in headers (or url does not exist)
    // NOTE: could potentially take up to 0-30 seconds , blocking further code execution (more or less depending on connection, target site, and local timeout settings))
    // if $followredirects == false: return the FIRST known httpcode (ignore redirects)
    // if $followredirects == true : return the LAST  known httpcode (when redirected)
    if (!$url || !is_string($url)) {
        return false;
    }
    $headers = @get_headers($url);

    if ($headers && is_array($headers)) {
        if ($followredirects) {
            // we want the last errorcode, reverse array so we start at the end:
            $headers = array_reverse($headers);
        }

        foreach ($headers as $hline) {
            // search for things like "HTTP/1.1 200 OK" , "HTTP/1.0 200 OK" , "HTTP/1.1 301 PERMANENTLY MOVED" , "HTTP/1.1 400 Not Found" , etc.
            // note that the exact syntax/version/output differs, so there is some string magic involved here
            if (preg_match('/^HTTP\/\S+\s+([1-9][0-9][0-9])\s+.*/', $hline, $matches)) {// "HTTP/*** ### ***"
                $code = $matches[1];
                return $code;
            }
        }
        // no HTTP/xxx found in headers:
        return false;
    }
    // no headers :
    return false;
}

$bites = random_bytes(16);
$worker = bin2hex($bites);


$db = get_database_connection();
$urls = getAllUrl($db);

echo "PROCESSED URL WITH WORKER $worker";
echo PHP_EOL;
foreach ($urls as $row) {
    // locking row with ID
    $getUrlRow = getUrl($db, $row->id);
    //continue on locked row
    if (!$getUrlRow) continue;
    $url = $getUrlRow->url;
    $status = $getUrlRow->status;

    $res = isValidUrl($db, $row->id, $url);

    if (!$res) {
        updateRow($db, $row->id, $worker, 'ERROR');
    }

    echo "ID=$row->id | URL=$url | STATUS=";
    echo $res ? 'DONE' : 'ERROR';
    echo PHP_EOL;


}
echo PHP_EOL . "ALL FINISHED!";

