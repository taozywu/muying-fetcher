<?php


/**
 * 抓取URL_TYPE的数据
 */

// 获取列表url
define("URL_TYPE", "https://list.tmall.com/ajax/allBrandShowForGaiBan.htm?q=[q]");


require_once "curl.lib.php";
require_once "process.lib.php";

require_once 'mysql.class.php';
$db = new DoMySQL('localhost', 'root', '123456', 'shop');



$params = array(
    "1" => "%C4%CC%B7%DB",
    "2" => "%D6%BD%C4%F2%BF%E3",
    "3" => "%C4%CC%C6%BF%2F%C4%CC%D7%EC",
    "4" => "%D0%C2%C9%FA%B6%F9%C0%F1%BA%D0",
    "5" => "%CD%C6%B3%B5",
    "6" => "%D3%A4%B6%F9%B4%B2",
    "7" => "%CE%A7%D7%EC",
    "8" => "%CB%AC%C9%ED%B7%DB",
    "9" => "%CA%AA%BD%ED",
    "10" => "%CE%C0%C9%FA%D6%BD",
);

fetch();

function fetch()
{
    global $db, $params;

    multi_process(1, true);

    while(true) {

        $tid = mp_counter('tid');

        if ($tid > 10) {
            break;
        }

        $url = str_replace("[q]", $params[$tid], URL_TYPE);

        echo $tid . "--" . $url . "\n";
        
        do{
            $html = curl_get($url);
        } while ($html === false);

        if (!$html) {
            continue;
        }

        $html = mb_convert_encoding($html,'UTF-8','GBK');

        $html = trim($html, "\r\n");

        $resultNew = json_decode($html, true);

        if (!$resultNew) {
            continue;
        }

        foreach ($resultNew as $r) {
            preg_match("/brand=(.*?)&amp;/", $r['href'], $match);
            $id = isset($match[1]) ? $match[1] : 0;

            $name = $r['title'];

            $count = $db->getOne("select count(1) from shop_type where parent_id={$tid} and tid={$id}");
            if ($count < 1) {
                $sql = "insert into shop_type (`tid`,`parent_id`,`name`) values('{$id}','{$tid}','{$name}')";
                
                file_put_contents("/data/www/muying/log_type.log", $sql . "\n\r", FILE_APPEND);
                $db->query($sql);
            }
        }

        rand_exit(100);

        // 暂时没用代理 做下睡眠防止太频繁了。
        usleep(1000);
    }
}
