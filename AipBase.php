<?php

namespace Luffy\TextCensor;

class AipBase
{
    /**
     * 获取access token url
     * @var string
     */
    public $accessTokenUrl = 'https://aip.baidubce.com/oauth/2.0/token';

    /**
     * 内容审核平台-文本 url
     * @var string
     */
    protected $textCensorUserDefinedUrl = 'https://aip.baidubce.com/rest/2.0/solution/v1/text_censor/v2/user_defined';

    /**
     * appId
     * @var string
     */
    protected $appId = '';

    /**
     * apiKey
     * @var string
     */
    protected $apiKey = '';

    /**
     * secretKey
     * @var string
     */
    protected $secretKey = '';

    /**
     * version
     * @var string
     */
    protected $version = '2_2_17';

    /**
     * 权限
     * @var array
     */
    protected $scope = 'brain_all_scope';

    /**
     * @var null
     */
    protected $isCloudUser = null;

    /**
     * @param string $appId
     * @param string $apiKey
     * @param string $secretKey
     */
    public function __construct($appId, $apiKey, $secretKey)
    {
        $this->appId = trim($appId);
        $this->apiKey = trim($apiKey);
        $this->secretKey = trim($secretKey);
    }

    /**
     * Api 请求
     * @param string $url
     * @param mixed $data
     * @return mixed
     */
    protected function request($url, $data, $headers = array())
    {
        try {
            $params = array();
            $authObj = $this->auth();
            if ($this->isCloudUser === false) {
                $params['access_token'] = $authObj['access_token'];
            }
            $params['aipSdk'] = 'php';
            $params['aipSdkVersion'] = $this->version;
            $response = $this->baiduRequest($url . "?" . http_build_query($params), $data, 1);

            $obj = $this->proccessResult($response['content']);
            if (!$this->isCloudUser && isset($obj['error_code']) && $obj['error_code'] == 110) {
                $authObj = $this->auth(true);
                $params['access_token'] = $authObj['access_token'];
                $response = $this->baiduRequest($url . "?" . http_build_query($params), $data, 1);
                $obj = $this->proccessResult($response['content']);
            }

            if (empty($obj) || !isset($obj['error_code'])) {
                $this->writeAuthObj($authObj);
            }
        } catch (\Exception $e) {
            return array(
                'error_code' => 'SDK108',
                'error_msg' => 'connection or read data timeout',
            );
        }

        return $obj;
    }

    /**
     * 格式化结果
     * @param $content string
     * @return mixed
     */
    protected function proccessResult($content)
    {
        return json_decode($content, true);
    }

    /**
     * 返回 access token 路径
     * @return string
     */
    private function getAuthFilePath()
    {
        return dirname(__FILE__) . '/runtime/' . md5($this->apiKey);
    }

    /**
     * 写入本地文件
     * @param array $obj
     * @return void
     */
    private function writeAuthObj($obj)
    {
        if ($obj === null || (isset($obj['is_read']) && $obj['is_read'] === true)) {
            return;
        }

        $obj['time'] = time();
        $obj['is_cloud_user'] = $this->isCloudUser;
        @file_put_contents($this->getAuthFilePath(), json_encode($obj));
    }

    /**
     * 读取本地缓存
     * @return array
     */
    private function readAuthObj()
    {
        $content = @file_get_contents($this->getAuthFilePath());
        if ($content !== false) {
            $obj = json_decode($content, true);
            $this->isCloudUser = $obj['is_cloud_user'];
            $obj['is_read'] = true;
            if ($this->isCloudUser || $obj['time'] + $obj['expires_in'] - 30 > time()) {
                return $obj;
            }
        }

        return null;
    }

    /**
     * 认证
     * @param bool $refresh 是否刷新
     * @return array
     */
    public function auth($refresh = false)
    {
        if (!$refresh) {
            $obj = $this->readAuthObj();
            if (!empty($obj)) {
                return $obj;
            }
        }

        $response = $this->baiduRequest(
            $this->accessTokenUrl,
            array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->apiKey,
                'client_secret' => $this->secretKey,
            )
        );

        $obj = json_decode($response['content'], true);

        $this->isCloudUser = !$this->isPermission($obj);
        return $obj;
    }

    /**
     * 判断认证是否有权限
     * @param array $authObj
     * @return boolean
     */
    protected function isPermission($authObj)
    {
        if (empty($authObj) || !isset($authObj['scope'])) {
            return false;
        }

        $scopes = explode(' ', $authObj['scope']);

        return in_array($this->scope, $scopes);
    }

    /**
     * @param $url
     * @param string $params
     * @param int $ispost
     * @return bool|string
     */
    private function baiduRequest($url, $params = "", $ispost = 0)
    {
        $params = is_array($params) ? http_build_query($params) : $params;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($ispost) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            if ($params) {
                curl_setopt($ch, CURLOPT_URL, $url . '?' . $params);
            } else {
                curl_setopt($ch, CURLOPT_URL, $url);
            }
        }
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 0) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);
        return array(
            'code' => $code,
            'content' => $response,
        );
    }
    /**
     * @param $message
     * @return mixed
     */
    public function textCensorUserDefined($message)
    {
        $data = array();
        $data['text'] = $message;
        return $this->request($this->textCensorUserDefinedUrl, $data);
    }
}