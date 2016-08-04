<?php

/**
 * 通过type来抓取列表
 * 
 */

define("URL", "https://list.tmall.com/search_product.htm?brand=[brand]&q=[keyword]&sort=d&search_condition=7[page]");

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


zhua();

function zhua()
{
    global $db, $params;

    // $params = $config['params'];

    multi_process(2, true);

    while(true) {

        $tid = mp_counter('tid');

        file_put_contents("/data/www/muying/log.log", "TID=" . $tid . "\r\n", FILE_APPEND | LOCK_EX);
        
        // 先从某个brandid的IDS
        $bids = getBrandIds($tid);

        // 循环IDS。
        foreach ($bids as $bid) {
            $p = 0;

            file_put_contents("/data/www/muying/log.log", "BID=" . $bid . "\r\n", FILE_APPEND | LOCK_EX);

            // 最多取前5页的数据。
            for ($i = 0; $i < 10; $i++) {
                $pageWhere = $i > 0 ? "&s=" . $i * 60 : "";
                $p ++;

                $url = str_replace("[brand]", $bid, URL);
                $url = str_replace("[page]", $pageWhere, $url);
                $url = str_replace("[keyword]", $params[$tid], $url);

                file_put_contents("/data/www/muying/log.log", "url=" . $url . "\r\n", FILE_APPEND | LOCK_EX);

                do{
                    $html = curl_get($url);
                } while ($html === false);

                if (!$html) {
                    continue;
                }

                // 转码
                $html = mb_convert_encoding($html,'UTF-8','GBK');

                // 其他符号处理
                $html = deleteHtml($html);

                doList($tid, $bid, $html);

                unset($html);

                // 暂时没用代理 做下睡眠防止太频繁了。
                usleep(1000);
            }

            // 暂时没用代理 做下睡眠防止太频繁了。
            usleep(1000);
        }

        unset($bids);

        rand_exit(100);

        // 暂时没用代理 做下睡眠防止太频繁了。
        usleep(1000);
    }
}

function deleteHtml($str)
{
    $str = trim($str);
    $str = str_replace("\t","",$str);
    $str = str_replace("\r\n","",$str);
    $str = str_replace("\r","",$str);
    $str = str_replace("\n","",$str);
    $str = str_replace(" "," ",$str);
    return trim($str);
}


function getBrandIds($tid)
{
    global $db;

    $sql = "select tid from shop_type where parent_id={$tid}";

    $data = $db->getAll($sql);
    $result = [];
    if ($data) {
        foreach ($data as $p) {
            $result[] = $p['tid'];
        }
    }

    unset($data);
    return $result;
}

function doList($tid, $bid, $html)
{
    preg_match_all("/data-item=\"(.*?)\"/", $html, $matches);
    $result['item_id'] = isset($matches[1]) ? $matches[1] : [];

    if ($result['item_id']) {

        preg_match_all("/user_number_id=(.*?)\"/", $html, $matches);
        $result['seller_id'] = isset($matches[1]) ? $matches[1] : [];

        // <img src="//img.alicdn.com/bao/uploaded/i2/1115154404/TB2Uv1BpFXXXXXSXpXXXXXXXXXX_!!1115154404.jpg_b.jpg">
        preg_match_all("/<img  (src|data-ks-lazyload)=(.*?)\"(.*?)_b.jpg\"/", $html, $matches);
        $result['first_img'] = isset($matches[3]) ? $matches[3] : [];

        preg_match_all("/<div class=\"productImg-wrap\"><a href=\"(.*?)\" class=\"productImg\"(.*?)<img(.*?)(src=|data-ks-lazyload=)(.*?)\/>/", $html, $matches);
        $result['url'] = isset($matches[1]) ? $matches[1] : [];

        preg_match_all("/<em title=\"(.*?)\"><b>/", $html, $matches);
        $result['price'] = isset($matches[1]) ? $matches[1] : [];

        preg_match_all("/class=\"productPrice-ave\">(.*?)<\/span>/", $html, $matches);
        $result['unit_price'] = isset($matches[1]) ? $matches[1] : [];
        
        preg_match_all("/is_b=1\" target=\"_blank\" title=\"(.*?)\"/", $html, $matches);
        $result['title'] = isset($matches[1]) ? $matches[1] : [];
        
        preg_match_all("/data-nick=\"(.*?)\"/", $html, $matches);
        $result['shop_name'] = isset($matches[1]) ? $matches[1] : [];
        
        preg_match_all("/<span>月成交 <em>(.*?)笔<\/em><\/span>/", $html, $matches);
        $result['sale_count'] = isset($matches[1]) ? $matches[1] : [];
        
        preg_match_all("/<span>评价(.*?)>(.*?)<\/a><\/span>/", $html, $matches);
        $result['rate_count'] = isset($matches[2]) ? $matches[2] : [];

        // 处理 result
        dealDoList($tid, $bid, $result);
    }
}

/**
 * https://detail.tmall.com/item.htm?id=12123123
 * @param  [type] $itemIds [description]
 * @return [type]          [description]
 */
function dealDoList($tid, $bid, $result)
{
    global $db;

    if ($result) {
        $arr = [];

        foreach ($result['item_id'] as $ik => $iv) {

            $urlRes = str_replace("//", "", $result['url'][$ik]);
            $urlRes = str_replace("&amp;", "&", $urlRes);
            $urlRes = str_replace("https:list.tmall.com", "https://", $urlRes);
            $urlRes = strpos($urlRes, "http") === false ? "https://" . $urlRes : $urlRes;

            $firstImg = str_replace("//", "", $result['first_img'][$ik]);
            $firstImg = str_replace("&amp;", "&", $firstImg);
            $firstImg = str_replace("https:list.tmall.com", "https://", $firstImg);
            $firstImg = $firstImg ? $firstImg . "_b.jpg" : "";
            $firstImg = strpos($firstImg, "http") === false ? "https://" . $firstImg : $firstImg;

            $arr[$ik]['item_id']    = $iv;
            $arr[$ik]['seller_id']  = @$result['seller_id'][$ik];
            $arr[$ik]['first_img']  = $firstImg;
            $arr[$ik]['url']        = $urlRes;
            $arr[$ik]['price']      = @$result['price'][$ik];
            $arr[$ik]['unit_price'] = @$result['unit_price'][$ik];
            $arr[$ik]['title']      = @$result['title'][$ik];
            $arr[$ik]['shop_name']  = @$result['shop_name'][$ik];
            $arr[$ik]['sale_count'] = @$result['sale_count'][$ik];
            $arr[$ik]['rate_count'] = @$result['rate_count'][$ik];
        }
        
        foreach ($arr as $pk => $pv) {
            $sql = "insert into shop_detail (item_id,type_parent_id,type_id,seller_id,first_img,url,price,unit_price,title,shop_name,sale_count,rate_count) values('".$pv['item_id']."','{$tid}','{$bid}','".$pv['seller_id']."','".$pv['first_img']."','".$pv['url']."','".$pv['price']."','".$pv['unit_price']."','".$pv['title']."','".$pv['shop_name']."','".$pv['sale_count']."','".$pv['rate_count']."')";
            file_put_contents("/data/www/muying/log_list.log", "sql=".$sql."\n\r", FILE_APPEND | LOCK_EX);
            $db->query($sql);
            // unset($arr[$pk]);
            usleep(1000);
        }

        unset($arr, $result);
    }
}

