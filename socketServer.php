<?php
/**
 * Created by PhpStorm.
 * User: 25754
 * Date: 2019/4/23
 * Time: 14:13
 */

class socketServer
{

    const LISTEN_SOCKET_NUM = 9;
    const LOG_PATH = "./log/";  //日志
    private $_ip = "127.0.0.1"; //ip
    private $_port = 1234;  //端口 要和前端创建WebSocket连接时的端口号一致
    private $_socketPool = array(); //socket池，即存放套接字的数组
    private $_master = null;    //创建的套接字对象

    public function __construct()
    {
        $this->initSocket();
    }

    // 创建WebSocket连接
    private function initSocket()
    {
        try {
            //创建socket套接字
            $this->_master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            // 设置IP和端口重用,在重启服务器后能重新使用此端口;
            socket_set_option($this->_master, SOL_SOCKET, SO_REUSEADDR, 1);
            //绑定地址与端口
            socket_bind($this->_master, $this->_ip, $this->_port);
            //listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
            socket_listen($this->_master, self::LISTEN_SOCKET_NUM);
        } catch (Exception $e) {
            $this->debug(array("code: " . $e->getCode() . ", message: " . $e->getMessage()));
        }
        //将socket保存到socket池中  （将套接字放入数组）默认把当前用户放在第一个
        $this->_socketPool[0] = array('resource' => $this->_master);
        $pid = getmypid();
        $this->debug(array("server: {$this->_master} started,pid: {$pid}"));
    }

    // 挂起进程遍历套接字数组，对数据进行接收、处理、发送
    public function run()
    {
        // 死循环  直到socket断开
        while (true) {
            try {
                
                $write = $except = NULL;
                // 从数组中取出resource列
                $sockets = array_column($this->_socketPool, 'resource');

               /* 
                $sockets 是一个存放文件描述符的数组。
                $write 是监听是否客户端写数据，传入NULL是不关心是否有写变化
                $except 是$sockets里面要派粗话的元素，传入null是监听全部
                最后一个参数是超时时间，0立即结束 n>1则最多n秒后结束，如遇某一个连接有新动态，则提前返回  null如遇某一个连接有新动态，则返回
                */
                // 接收套接字数字，监听他们的状态就是有新消息到或有客户端连接/断开时，socket_select函数才会返回，继续往下执行
                $read_num = socket_select($sockets, $write, $except, NULL);
                if (false === $read_num) {
                    $this->debug(array('socket_select_error', $err_code = socket_last_error(), socket_strerror($err_code)));
                    return;
                }

                // 遍历套接字数组
                foreach ($sockets as $socket) {

                    // 如果有新的连接进来
                    if ($socket == $this->_master) {

                        // 接收一个socket连接
                        $client = socket_accept($this->_master);
                        if ($client === false) {
                            $this->debug(['socket_accept_error', $err_code = socket_last_error(), socket_strerror($err_code)]);
                            continue;
                        }
                        //连接 并放到socket池中
                        $this->connection($client);
                    } else {

                        //接收已连接的socket数据，返回的是从socket中接收的字节数。
                        // 第一个参数：socket资源，第二个参数：存储接收的数据的变量，第三个参数：接收数据的长度
                        $bytes = @socket_recv($socket, $buffer, 2048, 0);

                        // 如果接收的字节数为0
                        if ($bytes == 0) {

                            // 断开连接
                            $recv_msg = $this->disconnection($socket);
                        } else {

                            // 判断有没有握手，没有握手进行握手，已经握手则进行处理
                            if ($this->_socketPool[(int)$socket]['handShake'] == false) {
                                // 握手
                                $this->handShake($socket, $buffer);
                                continue;
                            } else {
                                // 解析客户端传来的数据
                                $recv_msg = $this->parse($buffer);
                            }
                        }

                        // echo "<pre>";
                        // 业务处理，组装返回客户端的数据格式
                        $msg = $this->doEvents($socket, $recv_msg);
                        // print_r($msg);

                        socket_getpeername ( $socket  , $address ,$port );
                        $this->debug(array(
                            'send_success',
                            json_encode($recv_msg),
                            $address,
                            $port
                        ));
                        // 把服务端返回的数据写入套接字
                        $this->broadcast($msg);
                    }
                }
            } catch (Exception $e) {
                $this->debug(array("code: " . $e->getCode() . ", message: " . $e->getMessage()));
            }

        }

    }

    /**
     * 数据广播
     * @param $data
     */
    private function broadcast($data)
    {
        foreach ($this->_socketPool as $socket) {
            if ($socket['resource'] == $this->_master) {
                continue;
            }
            // 写入套接字
            socket_write($socket['resource'], $data, strlen($data));
        }
    }

    /**
     * 业务处理，在这可以对数据库进行操作,并返回客户端数据；根据不同类型，组装不同格式的数据
     * @param $socket
     * @param $recv_msg 客户端传来的数据
     * @return string
     */
    private function doEvents($socket, $recv_msg)
    {
        $msg_type = $recv_msg['type'];
        $msg_content = $recv_msg['msg'];
        $response = [];
        //echo "<pre>";
        switch ($msg_type) {
            case 'login':
            // 登陆上线信息
                $this->_socketPool[(int)$socket]['userInfo'] = array("username" => $msg_content, 'headerimg' => $recv_msg['headerimg'], "login_time" => date("h:i"));
                // 取得最新的名字记录
                $user_list = array_column($this->_socketPool, 'userInfo');
                $response['type'] = 'login';
                $response['msg'] = $msg_content;
                $response['user_list'] = $user_list;
                //print_r($response);

                break;
            case 'logout':
            // 退出信息
                $user_list = array_column($this->_socketPool, 'userInfo');
                $response['type'] = 'logout';
                $response['user_list'] = $user_list;
                $response['msg'] = $msg_content;
                //print_r($response);
                break;
            case 'user':
            // 发送的消息
                $userInfo = $this->_socketPool[(int)$socket]['userInfo'];
                $response['type'] = 'user';
                $response['from'] = $userInfo['username'];
                $response['msg'] = $msg_content;
                $response['headerimg'] = $userInfo['headerimg'];
                //print_r($response);
                break;
        }

        return $this->frame(json_encode($response));
    }

    /**
     * socket握手
     * @param $socket
     * @param $buffer  客户端接收的数据
     * @return bool
     */
    public function handShake($socket, $buffer)
    {
        $acceptKey = $this->encry($buffer);
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: " . $acceptKey . "\r\n\r\n";

        // 将socket写入缓冲区
        socket_write($socket, $upgrade, strlen($upgrade));
        // 标记握手已经成功，下次接受数据采用数据帧格式
        $this->_socketPool[(int)$socket]['handShake'] = true;
        socket_getpeername ( $socket  , $address ,$port );
        $this->debug(array(
            'hand_shake_success',
            $socket,
            $address,
            $port
        ));
        //发送消息通知客户端握手成功
        $msg = array('type' => 'handShake', 'msg' => '握手成功');
        $msg = $this->frame(json_encode($msg));
        socket_write($socket, $msg, strlen($msg));
        return true;
    }

    /**
     * 帧数据封装
     * @param $msg
     * @return string
     */
    private function frame($msg)
    {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }
        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;
        $data = implode('', $frame);
        return pack("H*", $data);
    }

    /**
     * 解析客户端的数据
     * @param $buffer
     * @return mixed
     */
    private function parse($buffer)
    {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return json_decode($decoded, true);
    }

    //提取 Sec-WebSocket-Key 信息并加密
    private function encry($req)
    {
        $key = null;
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $req, $match)) {
            $key = $match[1];
        }
        // 加密
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * 连接socket
     * @param $client
     */
    public function connection($client)
    {
        socket_getpeername ( $client  , $address ,$port );
        $info = array(
            'resource' => $client,
            'userInfo' => '',
            'handShake' => false,
            'ip' => $address,
            'port' => $port,
        );
        $this->_socketPool[(int)$client] = $info;
        $this->debug(array_merge(['socket_connect'], $info));
    }

    /**
     * 断开连接
     * @param $socket
     * @return array
     */
    public function disconnection($socket)
    {
        $recv_msg = array(
            'type' => 'logout',
            'msg' => @$this->_socketPool[(int)$socket]['userInfo']['username'],
        );
        unset($this->_socketPool[(int)$socket]);
        return $recv_msg;
    }

    /**
     * 日志
     * @param array $info
     */
    private function debug(array $info)
    {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);
        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
}

// 类外实例化
$sk = new socketServer();
// 运行
$sk -> run();