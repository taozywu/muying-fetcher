<?php

require_once "curl.lib.php";
require_once "process.lib.php";

require_once 'mysql.class.php';
$db = new DoMySQL('localhost', 'root', '123456', 'shop');

zhua();

function zhua()
{
    global $db, $tianMao;

    // $params = $config['params'];

    multi_process(1, true);

    while(true) {

        $id = mp_counter('tid');
        if ($id < @file_get_contents("/tmp/test_info")) {
            continue;
        }

        $info = $db->getRow("select * from shop_detail where id = $id order by id asc limit 1");

        $url = $info['url'];
        $itemId = $info['item_id'];
        $sellerId = $info['seller_id'];
        $parentTypeId = $info['type_parent_id'];
        $typeId = $info['type_id'];
        $tid = $info['id'];

        file_put_contents("/tmp/test_info", $tid);

        do{
            $html = curl_get($url);
        } while ($html === false);
        // 

        $html = deleteHtml($html);

        if (!$html) {
            continue;
        }

        if ($html) {

            $html = iconv("gbk", "utf-8", $html);

            preg_match_all("/<a href=\"(https:\/\/detail.tmall.com\/#|#)\"><img src=\"(.*?)_60x60q90.jpg\"/", $html, $matches);
            $result['detail_img'] = isset($matches[2]) ? $matches[2]: [];

            preg_match_all("/(正品保证|按时发货|极速退款|运费险|七天无理由退换)/", $html, $matches);
            
            $detailService = isset($matches[1]) ? $matches[1] : [];
            $detailService = array_unique($detailService);
            $result['detail_service'] = array(
                "zhengpin" => in_array("正品保证", $detailService) ? true : false,
                "fahuo" => in_array("按时发货", $detailService) ? true : false,
                "tuikuan" => in_array("极速退款", $detailService) ? true : false,
                "yunfeixian" => in_array("运费险", $detailService) ? true : false,
                "tuihuan" => in_array("七天无理由退换", $detailService) ? true : false,
            );

            preg_match_all("/<span class=\"shopdsr-score-con\">(.*?)<\/span>/", $html, $matches);
            $result['detail_score'] = isset($matches[1]) ? $matches[1] : [];
            
            // <span class="tm-shop-age-num">4</span>
            preg_match_all("/<span class=\"tm-shop-age-num\">(.*?)<\/span>/", $html, $matches);
            $result['detail_age'] = isset($matches[1]) ? $matches[1] : 0;
            
            preg_match_all("/(公 司 名：|所 在 地：|工商执照：)(.*?)<div class=\"right\">(.*?)<\/div>/", $html, $matches);
            $result['detail_company'] = array(
                "name" => isset($matches[3][0]) ? $matches[3][0] : "",
                "address" => isset($matches[3][1]) ? $matches[3][1] : "",
                "open_pic" => isset($matches[3][2]) ? $matches[3][2] : "",
            );
            
            preg_match_all("/生产日期:(.*?)至(.*?)<\/div>/", $html, $matches);
            $result['open_date'] = isset($matches[1]) ? $matches[1] : [];

            $result['config'] = fetchDetailConfig($itemId);

            $result['fav_count'] = fetchDetailFavCount($itemId);

            usleep(1000);

            // 更新shop_detail
            updateShopDetail($id, $result);

            // 去获取评论
            fetchDetailRate($parentTypeId, $typeId, $id, $itemId, $sellerId);

            // 暂时没用代理 做下睡眠防止太频繁了。
            usleep(1000);
        }
        rand_exit(100);

        // 暂时没用代理 做下睡眠防止太频繁了。
        usleep(1000);
    }
}

/**
 * https://count.taobao.com/counter3?_ksTS=1464608321349_207&callback=json&keys=ICCP_1_531844813180
 * @return [type] [description]
 */
function fetchDetailFavCount($itemId)
{
    $key = "ICCP_1_{$itemId}";
    $html = curl_get("https://count.taobao.com/counter3?_ksTS=1464608321349_207&callback=json&keys={$key}");
    $html = mb_convert_encoding($html,'UTF-8','GBK');
    $result = str_replace(array("json(", ")", ";"), "", $html);
    $result = json_decode($result, true);

    return isset($result[$key]) ? $result[$key] : 0;
}

/**
 * 更新shop_detail.
 * 
 * @param  [type] $id     [description]
 * @param  [type] $result [description]
 * @return [type]         [description]
 */
function updateShopDetail($id, $result)
{
    global $db;

    if (!$result) {
        return false;
    }

    $result['detail_img'] = str_replace("//", "", $result['detail_img']);
    $result['detail_img'] = str_replace("&amp;", "&", $result['detail_img']);
    $result['detail_img'] = str_replace("https:detail.tmall.com", "", $result['detail_img']);

    $resultNew = array(
        "shop_imgs" => $result['detail_img'] ? addslashes(json_encode(array_map('dealDetailImg', $result['detail_img']))) : "",
        "service_text" => $result['detail_service'] ? addslashes(json_encode($result['detail_service'])) : "",
        "score_miaoshu" => isset($result['detail_score'][0]) ? $result['detail_score'][0] : 0,
        "score_fuwu" => isset($result['detail_score'][1]) ? $result['detail_score'][1] : 0,
        "score_wuliu" => isset($result['detail_score'][2]) ? $result['detail_score'][2] : 0,
        "company_name" => isset($result['detail_company']['name']) ? trim(addslashes($result['detail_company']['name'])) : "",
        "company_address" => isset($result['detail_company']['address']) ? trim(addslashes($result['detail_company']['address'])) : "",
        "company_zhizhao" => isset($result['detail_company']['open_pic']) ? trim(addslashes($result['detail_company']['open_pic'])) : "",
        "open_time" => isset($result['open_date'][0]) ? trim($result['open_date'][0]) : "",
        "shop_age" => isset($result['detail_age'][0]) ? $result['detail_age'][0] : 0,
        "main_configs" => isset($result['config']) ? addslashes(json_encode($result['config'])): "",
        "fav_count" => isset($result['fav_count']) ? (int) $result['fav_count'] : 0,
        "score" => isset($result['detail_score'][0]) ? (int) $result['detail_score'][0] : 0,
    );

    $or  = "";
    $sql = "";
    foreach ($resultNew as $rk => $rv) {
        $sql .= "{$or}`{$rk}` = '{$rv}'";
        $or = ",";
    }

    if ($sql) {
        $s = "update shop_detail set " . $sql . " where id = $id";
        
        file_put_contents("/data/www/muying/log_info.log", $s . "\n\r\n\r", FILE_APPEND);
        $db->query($s);
    }

    return true;
}


/**
 * 处理图片。
 * 
 * @var [type]
 */
function dealDetailImg( $value )
{
    return $value . "_60x60q90.jpg";
}


/**
 * 过滤下特殊符号吧
 * @param  [type] $str [description]
 * @return [type]      [description]
 */
function deleteHtml($str)
{
    $str = trim($str);
    // $str = strip_tags($str,"");
    $str = str_replace("\t","",$str);
    $str = str_replace("\r\n","",$str);
    $str = str_replace("\r","",$str);
    $str = str_replace("\n","",$str);
    $str = str_replace(" "," ",$str);
    return trim($str);
}

/**
 * https://mdetail.tmall.com/mobile/itemPackage.do?itemId=41410214981
 * @return [type] [description]
 */
function fetchDetailConfig($itemId)
{
    $html = curl_get("https://mdetail.tmall.com/mobile/itemPackage.do?itemId={$itemId}");
    $html = mb_convert_encoding($html,'UTF-8','GBK');
    $result = json_decode($html, true);
    $res = [];
    if (!isset($result['model']['list'][0]['v'])) {
        return $res;
    }

    foreach ($result['model']['list'][0]['v'] as $r) {
        $res[$r['k']] = $r['v'];
    }
    unset($result, $html);
    return $res;
}


/**
 * https://rate.tmall.com/listTagClouds.htm?itemId=41410214981&isAll=true&isInner=true&callback=jsonp_review_tags
 * @return [type] [description]
 */
function fetchDetailRateHead($itemId)
{
    $html = curl_get("https://rate.tmall.com/listTagClouds.htm?itemId={$itemId}&isAll=true&isInner=true&callback=jsonp_review_tags");
    $html = mb_convert_encoding($html,'UTF-8','GBK');
    $result = str_replace(array("jsonp_review_tags(", ")"), "", $html);
    $result = json_decode($result, true);
    
    $res = [];
    if (!isset($result['tags'])) {
        return $res;
    }

    $res['good_num'] = $result['tags']['dimenSum'];
    $res['rate_head_num'] = $result['tags']['rateSum'];

    foreach ($result['tags']['tagClouds'] as $r) {
        $res['list'][] = array(
            "count" => (int)$r['count'],
            "is_good" => (int)$r['posi'],
            "tag" => $r['tag'],
        );
    }

    unset($html, $result);
    return $res;
}


/**
 * https://rate.tmall.com/list_detail_rate.htm?itemId=41410214981&sellerId=1603022933&order=3&&currentPage=1&pageSize=10&callback=jsonp431
 * @return [type] [description]
 */
function fetchDetailRate($parentTypeId, $typeId, $id, $itemId, $sellerId)
{
    global $db;

    for($i = 1; $i < 100; $i ++) {
        $html = curl_get("https://rate.tmall.com/list_detail_rate.htm?itemId={$itemId}&sellerId={$sellerId}&order=3&&currentPage={$i}&pageSize=10&callback=jsonp431");
        $html = mb_convert_encoding($html,'UTF-8','GBK');
        $result = str_replace(array("jsonp431(", ")"), "", $html);
        $result = json_decode($result, true);
        
        $rateList = isset($result['rateDetail']['rateList']) ? $result['rateDetail']['rateList'] : [];

        if ($rateList) {
            foreach ($rateList as $r) {

                $aliMallSeller = (int)$r['aliMallSeller'];
                $anony = (int)$r['anony'];
                $appendComment = $r['appendComment'] ? json_encode($r['appendComment']) : "";
                $attributes = $r['attributes'];
                $aucNumId = $r['aucNumId'];
                $auctionPicUrl = $r['auctionPicUrl'];
                $auctionPrice = $r['auctionPrice'];
                $auctionSku = $r['auctionSku'];
                $auctionTitle = $r['auctionTitle'];
                $buyCount = $r['buyCount'];
                $carServiceLocation = $r['carServiceLocation'];
                $cmsSource = $r['cmsSource'];
                $displayRatePic = $r['displayRatePic'];
                $displayRateSum = $r['displayRateSum'];
                $displayUserLink = $r['displayUserLink'];
                $displayUserNick = $r['displayUserNick'];
                $displayUserNumId = $r['displayUserNumId'];
                $displayUserRateLink = $r['displayUserRateLink'];
                $dsr = $r['dsr'];

                $fromMall = (int)$r['fromMall'];
                $fromMemory = $r['fromMemory'];
                $gmtCreateTime = $r['gmtCreateTime'];
                $id = $r['id'];
                $pics = $r['pics'] ? json_encode($r['pics']) : "";
                $picsSmall = $r['picsSmall'];
                $position = $r['position'];
                $rateContent = $r['rateContent'];
                $rateDate = $r['rateDate'];
                $reply = $r['reply'];
                $sellerId = $r['sellerId'];
                $serviceRateContent = $r['serviceRateContent'];
                $structuredRateList = $r['structuredRateList'] ? json_encode($r['structuredRateList']) : "";
                $tamllSweetLevel = $r['tamllSweetLevel'];
                $tmallSweetPic = $r['tmallSweetPic'];
                $tradeEndTime = $r['tradeEndTime'];
                $tradeId = $r['tradeId'];
                $useful = (int)$r['useful'];
                $userIdEncryption = $r['userIdEncryption'];
                $userInfo = $r['userInfo'];
                $userVipLevel = $r['userVipLevel'];
                $userVipPic = $r['userVipPic'];

                $sql = "insert into shop_detail_rate (`iid`,`type_parent_id`,`type_id`,`detail_id`,`ali_mall_seller`,`anony`,`append_comment`,`attributes`,`aucnum_id`,`auction_pic_url`,`auction_price`,`auction_sku`,`auction_title`,`buy_count`,`car_service_location`,`cms_source`,`display_rate_pic`,`display_rate_sum`,`display_user_link`,`display_user_nick`,`display_user_num_id`,`display_user_rate_link`,`dsr`,`from_mall`,`from_memory`,`gmt_create_time`,`pics`,`pics_small`,`position`,`rate_content`,`rate_date`,`reply`,`seller_id`,`service_rate_content`,`structured_rate_list`,`tamll_sweet_level`,`tmall_sweet_pic`,`trade_end_time`,`trade_id`,`useful`,`user_id_encryption`,`user_info`,`user_vip_level`,`user_vip_pic`) values ('{$id}','{$parentTypeId}','{$typeId}','{$itemId}','{$aliMallSeller}','{$anony}','{$appendComment}','{$attributes}','{$aucNumId}','{$auctionPicUrl}','{$auctionPrice}','{$auctionSku}','{$auctionTitle}','{$buyCount}','{$carServiceLocation}','{$cmsSource}','{$displayRatePic}','{$displayRateSum}','{$displayUserLink}','{$displayUserNick}','{$displayUserNumId}','{$displayUserRateLink}','{$dsr}','{$fromMall}','{$fromMemory}','{$gmtCreateTime}','{$pics}','{$picsSmall}','{$position}','{$rateContent}','{$rateDate}','{$reply}','{$sellerId}','{$serviceRateContent}','{$structuredRateList}','{$tamllSweetLevel}','{$tmallSweetPic}','{$tradeEndTime}','{$tradeId}','{$useful}','{$userIdEncryption}','{$userInfo}','{$userVipLevel}','{$userVipPic}')";

                file_put_contents("/data/www/muying/log_info.log", $sql . "\n\r", FILE_APPEND);
                $db->query($sql);

                usleep(1000);
            }
        }

        usleep(1000);
    }
}
