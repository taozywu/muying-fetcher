<?php
if(php_sapi_name() != 'cli'){
	return;
}

declare(ticks = 1);

// 中断信号
$signals = array(
    SIGINT  => "SIGINT",
    SIGHUP  => "SIGHUP",
    SIGQUIT => "SIGQUIT"
);

// 命令行颜色输出
$colors['red']         = "\33[31m";
$colors['green']       = "\33[32m";
$colors['yellow']      = "\33[33m";
$colors['end']         = "\33[0m";
$colors['reverse']     = "\33[7m";
$colors['purple']      = "\33[35m";
$colors['cyan']        = "\33[36m";

// 程序开始运行时间
$start_time = time();

// 父进程PID
$fpid = getmypid();

// 文件保存目录，/dev/shm/是内存空间映射到硬盘上，IO速度快。
// 有些环境上可能会没有这个目录，比如OpenVZ的VPS，这个路径实际是在硬盘上
if(file_exists('/dev/shm/') && is_dir('/dev/shm/')){
    $process_file_dir = '/dev/shm/';
}else{
    $process_file_dir = '/tmp/';
}

// 清理过期资源(文件和SEM信号锁)，每次程序执行都需要调用，清除掉之前执行时的残留文件。
clear_process_resource();

// 判断是否在子进程中
function is_subprocess(){
	global $fpid;
	if(getmypid() != $fpid){
		return true;
	}else{
		return false;
	}
}

/**
 * 多进程计数
 *
 * 1,用于多进程运行时的任务分配与计数，比如要采集某DZ论坛的帖子，则可以将计数器用于/thread-tid-1-1.html中
 * 的tid，实现进程间的协调工作
 * 2,由于shm_*系列函数的操作不够灵活，所以这里主要用于/proc/和/dev/shm/这二个目录来实现数据的读写（内存操
 * 作，不受硬盘IO性能影响），用semaphore信号来实现锁定和互斥机制
 * 3,编译PHP时需要使用参数--enable-sysvmsg安装所需的模块
 *
 * @param   string  $countername    计数器名称
 * @param   mix     $update         计数器的更新值，如果是'init'，计数器则被初始化为0
 * @return int                      返回计数
 */
function mp_counter($countername, $update=1){
    global $process_file_dir;
    $time = date('Y-m-d H:i:s');

    // 父进程PID或者自身PID
    $top_pid = get_ppid();

    // 系统启动时间
    $sysuptime = get_sysuptime();

    // 进程启动时间
    $ppuptime = get_ppuptime($top_pid);

    // 由父进程ID确定变量文件路径前缀
    $path_pre = "{$process_file_dir}mp_counter_{$countername}_pid_{$top_pid}_";

    // 由于系统启动时间和当前父进程启动时间(jiffies格式)确定计数使用的文件
    $cur_path = "{$path_pre}btime_{$sysuptime}_ptime_{$ppuptime}";

    // 更新计数，先锁定
    $lock = sem_lock();

    if(!file_exists($cur_path)){
        // 调试代码。个别系统上启动时间会变化，造成文件路径跟随变化，最终导致计数归0。
        // $log = "[{$time}] - {$countername}($cur_path) - init\n";
        // file_put_contents('/tmp/process.log', $log, FILE_APPEND);

        $counter = 0;
    }else{
        // 理论上在这里，文件是一定存在的
        $counter = file_get_contents($cur_path);
    }

    // 更新记数, 继续研究下判断init不能用==
    if($update === 'init'){
        // 如果接收到更新值为init,或者变量文件不存在，则将计数初始化为0。
        $new_counter = 0;
    }else{
        $new_counter = $counter + $update;
    }

    // 写入计数，解锁
    file_put_contents($cur_path, $new_counter);
    sem_unlock($lock);

    return $new_counter;
}

/**
 * 创建多进程
 *
 * 1,通过mp_counter()函数实现进程间的任务协调
 * 2,由于PHP进程可能会由于异常而退出(主要是segment fault)，并且由于处理内存泄露的问题需要子进程主动退出，本函数可以实现自动建立
 * 新的进程，使子进程数量始终保持在$num的数量
 * 3,编译PHP时需要使用参数--enable-pcntl安装所需的模块
 * 4,如果在子进程中调用了exit(9)，那么主进程和所有子进程都将退出
 *
 * @param int       $num            进程数量
 * @param bool      $stat           结束后是否输出统计信息
 */
function multi_process($num, $stat=FALSE){
    global $colors, $signals;
    extract($colors);

    if(empty($num)){
        $num = 1;
    }

    // 记录进程数量，统计用
	mp_counter('process_num', 'init');
    mp_counter('process_num', $num);

    // 子进程数量
    $child = 0;

    // 任务完成标识
    $task_finish = FALSE;

    while(TRUE) {

        // 清空子进程退出状态
        unset($status);

        // 如果任务未完成，并且子进程数量没有达到最高，则创建
        if ($task_finish == FALSE && $child < $num) {
            $pid = pcntl_fork();
            if ($pid) {
                // 有PID，这里是父进程
                $child++;

                // 注册父进程的信号处理函数
                if($stat){
                    foreach ($signals as $signal => $name) {
                        if (!pcntl_signal($signal, "signal_handler")) {
                            die("Install signal handler for {$name} failed");
                        }
                    }
                }

                //$stat && pcntl_signal(SIGINT, "signal_handler");

                echo "{$reverse}{$green}[+]New Process Forked: {$pid}{$end}\n";
                mp_counter('t_lines', -1);
            } else {
                // fork后，子进程将进入到这里

                // 1,注册一个信号，处理函数直接exit()，目的是让子进程不进行任何处理，仅由主进程处理这个信号
                // 2,貌似不单独为子进程注册信号的话，子进程将使用父进程的处理函数
                $stat && pcntl_signal(SIGINT, "sub_process_exit");

                // 注册信号后直接返回，继续处理主程序的后续部分。
                return;
            }
        }

        // 子进程管理部分
        if($task_finish){
            // 如果任务已经完成
            if ($child > 0) {
                // 如果还有子进程未退出，则等待，否则退出
                pcntl_wait($status);
                $child--;
            } else {
                // 所有子进程退出，父进程退出

                // 统计信息
                $stat && final_stat();
				
				// 这里修改，父进程不退出，改为返回，继续处理后续任务，如删除文件
                //exit();
				return;
            }
        }else{
            // 如果任务未完成
            if($child >= $num){
                // 子进程已经达到数量，等待子进程退出
                pcntl_wait($status);
                $child--;
            }else{
                // 子进程没有达到数量，下一循环继续创建
            }
        }

        // 子进程退出状态码为9时，则判断为所有任务完成，然后等待所有子进程退出
        if(!empty($status) && pcntl_wexitstatus($status) == 9){
            $task_finish = TRUE;
        }
    }
}


/**
 * 检查同一脚本是否已经在运行，确保只有一个实例运行
 * @return bool
 */
function single_process(){
    if(get_ppid() !== getmypid()){
        echo "Fatal Error: Can't call single_process() in child process!\n";
        exit(9);
    }
    $self = get_path();
    $files = glob("/proc/*/exe");
    foreach($files as $exe_path){
        if(stripos(@readlink($exe_path), 'php') !== FALSE
            && stripos(readlink($exe_path), 'php-fpm') === FALSE){
            // 如果是PHP进程，进入到这里
            preg_match("/\/proc\/(\d+)\/exe/", $exe_path, $preg);
            if(!empty($preg[1]) && get_path($preg[1]) == $self && $preg[1] != getmypid()){
                exit("Fatal Error: This script is already running!\n");
            }
        }
    }
    return TRUE;
}


/**
 * 获取脚本自身的绝对路径，要求必须以php foo.php的方式运行
 * @param int $pid
 * @return string
 */
function get_path($pid=0){
    if($pid == 0){
        $pid = get_ppid();
    }
    $cwd = @readlink("/proc/{$pid}/cwd");
    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
    preg_match("/php(.*?\.php)/", $cmdline, $preg);
	if(empty($preg[1])){
		return FALSE;
	}else{
		$script = $preg[1];
	}
    

    if(strpos($script, '/') === FALSE || strpos($script, '..') !== FALSE){
        $path = "{$cwd}/{$script}";
    }else{
        $path = $script;
    }
    $path =  realpath(strval(str_replace("\0", "", $path)));
    if(!file_exists($path)){
        exit("Fatal Error: Can't located php script path!\n");
    }

    return $path;
}

function final_stat(){
    global $colors;
    extract($colors);

    // 时间统计
    global $start_time;
    $usetime = time() - $start_time;
    $usetime < 1 && $usetime = 1;
    $H = floor($usetime / 3600);
    $i = ($usetime / 60) % 60;
    $s = $usetime % 60;
    $str_usetime = sprintf("%02d hours, %02d minutes, %02d seconds", $H, $i, $s);
    echo "\n{$green}========================================================================\n";
    echo " All Task Done! Used Time: {$str_usetime}({$usetime}s).\n";

    // curl抓取统计
    $fetch_total = mp_counter('fetch_total', 0);
    $fetch_success = $fetch_total - mp_counter('fetch_failed', 0);

    $down_total = mp_counter('down_total', 0);
    $down_success = $down_total - mp_counter('down_failed', 0);

    $header_total = mp_counter('header_total', 0);
    $header_success = $header_total - mp_counter('header_failed', 0);

    $download_size = hs(mp_counter('download_size', 0));

    echo " Request Stat: Fetch({$fetch_success}/{$fetch_total}), Header({$header_success}/{$header_total}), ";
    echo "Download({$down_success}/{$down_total}, {$download_size}).\n";

    // curl流量统计
    $bw_in =  hs(mp_counter('download_total', 0));
    $rate_down = hbw(mp_counter('download_total', 0) / $usetime);
    echo " Bandwidth Stat(rough): Total({$bw_in}), Rate($rate_down).\n";

    // 效率统计
    $process_num = mp_counter('process_num', 0);
    $fetch_rps = hnum($fetch_success / $usetime);
    $fetch_rph = hnum($fetch_success * 3600 / $usetime);
    $fetch_rpd = hnum($fetch_success * 3600 * 24 / $usetime);
    echo " Efficiency: Process({$reverse}{$process_num}{$end}{$green}), Second({$fetch_rps}), ";
    echo "Hour({$fetch_rph}), Day({$reverse}{$fetch_rpd}{$end}{$green}).\n";

    echo "========================================================================{$end}\n";
}

/**
 * @param $signal
 */
function signal_handler($signal) {
    global $colors, $signals;
    extract($colors);
    if(array_key_exists($signal, $signals)){
        kill_all_child();
        echo "\n{$cyan}Ctrl + C caught, quit!{$end}\n";
        final_stat();
        exit();
    }
}

function sub_process_exit(){
    exit(9);
}

function hnum($num){
    if($num < 10){
        $res = round($num, 1);
    }elseif($num < 10000){
        $res = floor($num);
    }elseif($num < 100000){
        $res = round($num/10000, 1) . 'w';
    }else{
        $res = floor($num/10000) . 'w';
    }
    return $res;
}

/**
 * 人性化显示带宽速率
 *
 * @param $size   byte字节数
 * @return string
 */
function hbw($size) {
    $size *= 8;
    if($size > 1024 * 1024 * 1024) {
        $rate = round($size / 1073741824 * 100) / 100 . ' Gbps';
    } elseif($size > 1024 * 1024) {
        $rate = round($size / 1048576 * 100) / 100 . ' Mbps';
    } elseif($size > 1024) {
        $rate = round($size / 1024 * 100) / 100 . ' Kbps';
    } else {
        $rate = round($size) . ' Bbps';
    }
    return $rate;
}


/**
 * 人性化显示数据量
 *
 * @param $size
 * @return string
 */
function hs($size) {
    if($size > 1024 * 1024 * 1024) {
        $size = round($size / 1073741824 * 100) / 100 . ' GB';
    } elseif($size > 1024 * 1024) {
        $size = round($size / 1048576 * 100) / 100 . ' MB';
    } elseif($size > 1024) {
        $size = round($size / 1024 * 100) / 100 . ' KB';
    } else {
        $size = round($size) . ' Bytes';
    }
    return $size;
}

/**
 * 杀死所有子进程
 */
function kill_all_child(){
    $ppid = getmypid();
    $files = glob("/proc/*/stat");
    foreach($files as $file){
        if(is_file($file)){
            $sections = explode(' ', file_get_contents($file));
            if($sections[3] == $ppid){
                posix_kill($sections[0], SIGTERM);
            }
        }
    }
}

if(!function_exists('get_ppid')){
    function get_ppid(){
        // 这里需要识别出是在子进程中调用还是在父进程中调用，不同的形式，保存的变量内容的文件位置需要保持一致
        $ppid = posix_getppid();
        // 理论上，这种判断方式可能会出坑。但在实际使用中，除了fork出的子进程外，不太可能让PHP进程的父进程的程序名中出现php字样。
        if(strpos(readlink("/proc/{$ppid}/exe"), 'php') === FALSE){
            $pid = getmypid();
        }else{
            $pid = $ppid;
        }
        return $pid;
    }
}

// 以进程(多进程运行时，使用父进程)为单位，每个进程使用一个锁。
function sem_lock($lock_name=NULL){
    global $process_file_dir;
    $pid = get_ppid();
    if(empty($lock_name)){
        $lockfile = "{$process_file_dir}sem_keyfile_main_pid_{$pid}";
    }else{
        $lockfile = "{$process_file_dir}sem_keyfile_{$lock_name}_pid_{$pid}";
    }
    if(!file_exists($lockfile)){
        touch($lockfile);
    }
    $shm_id = sem_get(ftok($lockfile, 'a'), 1, 0600, true);
    if(sem_acquire($shm_id)){
        return $shm_id;
    }else{
        return FALSE;
    }
}

// 解除锁
function sem_unlock($shm_id){
    sem_release($shm_id);
}

// 清理资源(文件和SEM信号锁)
function clear_process_resource(){
    global $process_file_dir;

    // 清除sem的文件和信号量
    $files = glob("{$process_file_dir}sem_keyfile*pid_*");
    foreach($files as $file){
        preg_match("/pid_(\d*)/", $file, $preg);
        $pid = $preg[1];
        $exe_path = "/proc/{$pid}/exe";
        // 如果文件不存在则说明进程不存在，判断是否为PHP进程，排除php-fpm进程
        if(!file_exists($exe_path)
            || stripos(readlink($exe_path), 'php') === FALSE
            || stripos(readlink($exe_path), 'php-fpm') === TRUE){
			$sem = @sem_get(@ftok($file, 'a'));
			if($sem){
				@sem_remove($sem);
			}
            @unlink($file);
        }
    }

    // 清除mp_counter的文件（仅此类型文件不可重用，所以严格处理，匹配系统启动时间和进程启动时间）
    $files = glob("{$process_file_dir}mp_counter*");
    foreach($files as $file){
        preg_match("/pid_(\d*)_btime_(\d*)_ptime_(\d*)/", $file, $preg);
        $pid = $preg[1];
        $btime = $preg[2];
        $ptime = $preg[3];
        $exe_path = "/proc/{$pid}/exe";

        // 清除文件
        if(!file_exists($exe_path)
            || stripos(readlink($exe_path), 'php') === FALSE
            || stripos(readlink($exe_path), 'php-fpm') === TRUE
            || $btime != get_sysuptime()
            || $ptime != get_ppuptime($pid)){
            @unlink($file);
        }
    }
}

// 系统启动时间
function get_sysuptime(){
    preg_match("/btime (\d+)/", file_get_contents("/proc/stat"), $preg);
    return $preg[1];
}

// 如果是在子进程中调用，则取父进程的启动时间。如果不是在子进程中调用，则取自身启动时间。时间都是jiffies格式。
function get_ppuptime($pid){
    $stat_sections = explode(' ', file_get_contents("/proc/{$pid}/stat"));
    return $stat_sections[21];
}

// 防止PHP进程内存泄露，每个子进程执行完一定数量的任务就退出。
function rand_exit($num=100){
    if(rand(floor($num*0.5), floor($num*1.5)) === $num){
        exit();
    }
}

// 单次的任务结果输出函数
function mp_msg(){
    global $start_time, $colors;
    extract($colors);

    // 整理统计信息
    $msg = date('[H:i:s]');
    $max = 0;
    $msg_arr = func_get_args();

    foreach ($msg_arr as $k=>$msg_array) {
        foreach($msg_array as $key=>$val) {
            $msg_array[$key] = $val;
            if(is_int($key)){
                $msg .= " $val";
            }else{
                $msg .= " {$key}:$val";
            }
            if(strlen($val) > strlen($msg_array[$max])){
                $max = $key;
            }
        }
    }

    // cron方式运行
    if(empty($_SERVER['SSH_TTY'])){
        $msg = preg_replace("/\\\33\[\d\dm/", '', $msg);
        echo "{$msg}\n";
        return;
    }

    $lock = sem_lock('mp_msg');
    $t_lines = mp_counter('t_lines', -1);
    if($t_lines <= 1){
        mp_counter('t_lines', 'init');
        mp_counter('t_lines', shell_exec('tput lines'));
        mp_counter('t_cols', 'init');
        mp_counter('t_cols', shell_exec('tput cols'));
    }
    sem_unlock($lock);

    $t_cols = mp_counter('t_cols', 0);
    $msg_len = strlen($msg);
    if($msg_len > $t_cols){
        $cut_len = strlen($msg_array[$max]) - ($msg_len - $t_cols);
        $msg = str_replace($msg_array[$max], substr($msg_array[$max], 0, $cut_len), $msg);
    }
    echo "{$msg}\n";

    if($t_lines <= 1){
        $usetime = time() - $start_time;
        $usetime < 1 && $usetime = 1;
        $H = floor($usetime / 3600);
        $i = ($usetime / 60) % 60;
        $s = $usetime % 60;
        $str_usetime = sprintf("%02d:%02d:%02d", $H, $i, $s);

        $process_num = mp_counter('process_num', 0);

        $fetch_total = mp_counter('fetch_total', 0);
        $fetch_success = $fetch_total - mp_counter('fetch_failed', 0);
        $fetch = hnum($fetch_success);
        $fetch_all = hnum($fetch_total);
        $fetch_rpd = hnum($fetch_success * 3600 * 24 / $usetime);

        echo "{$reverse}{$purple}";
        echo "Stat: Time({$str_usetime}) Process({$process_num}) Fetch({$fetch}/{$fetch_all}) Day({$fetch_rpd})";
        echo "{$end}\n";
        flush();
    }

}
