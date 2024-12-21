<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class allwaf implements DeployInterface
{
    private $logger;
    private $url = 'https://api.allwaf.cn';
    private $accessKeyId;
    private $accessKey;
    private $usertype = 'user';
    private $proxy;
    private $accessToken;

    public function __construct($config)
    {
        $this->accessKeyId = $config['accessKeyId'];
        $this->accessKey = $config['accessKey'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->accessKeyId) || empty($this->accessKey)) throw new Exception('必填参数不能为空');
        $this->getAccessToken();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domainList'];
        if (empty($domains)) throw new Exception('没有设置要部署的域名');

        $this->getAccessToken();

        $params = [
            'domains' => $domains,
            'offset' => 0,
            'size' => 10,
        ];
        try {
            $data = $this->request('/SSLCertService/listSSLCerts', $params);
        } catch (Exception $e) {
            throw new Exception('获取证书列表失败：' . $e->getMessage());
        }
        $list = json_decode(base64_decode($data['sslCertsJSON']), true);
        if (!$list || empty($list)) {
            throw new Exception('证书列表为空');
        }
        $this->log('获取证书列表成功(total=' . count($list) . ')');

        $certInfo = openssl_x509_parse($fullchain, true);

        foreach ($list as $row) {
            $params = [
                'sslCertId' => $row['id'],
                'isOn' => true,
                'name' => $row['name'],
                'description' => $row['description'],
                'serverName' => $row['serverName'],
                'isCA' => false,
                'certData' => base64_encode($fullchain),
                'keyData' => base64_encode($privatekey),
                'timeBeginAt' => $certInfo['validFrom_time_t'],
                'timeEndAt' => $certInfo['validTo_time_t'],
                'dnsNames' => $domains,
                'commonNames' => [$certInfo['issuer']['CN']],
            ];
            $this->request('/SSLCertService/updateSSLCert', $params);
            $this->log('证书ID:' . $row['id'] . '更新成功！');
        }
    }

    private function getAccessToken()
    {
        $path = '/APIAccessTokenService/getAPIAccessToken';
        $params = [
            'type' => $this->usertype,
            'accessKeyId' => $this->accessKeyId,
            'accessKey' => $this->accessKey,
        ];
        $result = $this->request($path, $params);
        if (isset($result['token'])) {
            $this->accessToken = $result['token'];
        } else {
            throw new Exception('登录成功，获取AccessToken失败');
        }
    }

    private function request($path, $params = null)
    {
        $url = $this->url . $path;
        $headers = [];
        $body = null;
        if ($this->accessToken) {
            $headers[] = 'X-Cloud-Access-Token: ' . $this->accessToken;
        }
        if ($params) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($params);
        }
        $response = curl_client($url, $body, null, null, $headers, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 200) {
            return isset($result['data']) ? $result['data'] : null;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            if (!empty($response['body'])) $this->log('Response:' . $response['body']);
            throw new Exception('返回数据解析失败');
        }
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }
}