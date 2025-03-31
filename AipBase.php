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
        $this->appId     = trim($appId);
        $this->apiKey    = trim($apiKey);
        $this->secretKey = trim($secretKey);
    }

    /**
     * Api 请求
     * @param string $url
     * @param array  $data
     * @param array  $headers
     * @return array
     */
    protected function request($url, $data, $headers = [])
    {
        try {
            $params      = [];
            $authContent = $this->auth();
            if ($this->isCloudUser === false) {
                $params['access_token'] = $authContent['access_token'];
            }
            $params['aipSdk']        = 'php';
            $params['aipSdkVersion'] = $this->version;
            $response                = $this->baiduWpRequest($url . '?' . http_build_query($params), $data, 1);

            $result = $this->processResult($response['content']);
            if (!$this->isCloudUser && isset($result['error_code']) && $result['error_code'] == 110) {
                $authContent            = $this->auth(true);
                $params['access_token'] = $authContent['access_token'];
                $response               = $this->baiduWpRequest($url . '?' . http_build_query($params), $data, 1);
                $result                 = $this->processResult($response['content']);
            }

            if (empty($result) || !isset($result['error_code'])) {
                $this->writeAuth($authContent);
            }
        } catch (\Exception $e) {
            return [
                'error_code' => 'SDK108',
                'error_msg'  => 'connection or read data timeout',
                'message'    => $e->getMessage(),
            ];
        }

        return $result;
    }

    /**
     * 格式化结果
     * @param $content string
     * @return array
     */
    protected function processResult($content)
    {
        return json_decode($content, true);
    }

    /**
     * 返回 access token 路径
     * @return string
     */
    private function getAuthFilePath()
    {
        return dirname(__DIR__) . '/runtime/' . md5($this->apiKey);
    }

    /**
     * 写入本地文件
     * @param array $content
     * @return void
     */
    private function writeAuth($content)
    {
        if ($content === null || (isset($content['is_read']) && $content['is_read'] === true)) {
            return;
        }

        $content['time']          = time();
        $content['is_cloud_user'] = $this->isCloudUser;
        @file_put_contents($this->getAuthFilePath(), json_encode($content));
    }

    /**
     * 读取本地缓存
     * @return array|null
     */
    private function readAuth()
    {
        $content = @file_get_contents($this->getAuthFilePath());
        if ($content !== false) {
            $result            = json_decode($content, true);
            $this->isCloudUser = $result['is_cloud_user'];
            $result['is_read'] = true;
            if ($this->isCloudUser || $result['time'] + $result['expires_in'] - 30 > time()) {
                return $result;
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
            $result = $this->readAuth();
            if (!empty($result)) {
                return $result;
            }
        }

        $response = $this->baiduWpRequest(
            $this->accessTokenUrl,
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->apiKey,
                'client_secret' => $this->secretKey,
            ]
        );

        $result = json_decode($response['content'], true);

        $this->isCloudUser = !$this->isPermission($result);
        return $result;
    }

    /**
     * 判断认证是否有权限
     * @param array $authContent
     * @return bool
     */
    protected function isPermission($authContent)
    {
        if (empty($authContent) || !isset($authContent['scope'])) {
            return false;
        }

        $scopes = explode(' ', $authContent['scope']);

        return in_array($this->scope, $scopes);
    }

    /**
     * @param              $url
     * @param array|string $params
     * @param int          $ispost
     * @return array
     */
    private function baiduWpRequest($url, $params = '', $ispost = 0)
    {
        $args = array(
            'timeout' => '15'
        );
        if ($ispost) {
            $args['body'] = $params;
            $response     = wp_remote_post($url, $args);
        } else {
            $params = is_array($params) ? http_build_query($params) : $params;
            if ($params) {
                $response = wp_remote_get($url . '?' . $params, $args);
            } else {
                $response = wp_remote_get($url, $args);
            }
        }
        $body = '';
        if (is_array($response) && !is_wp_error($response) && $response['response']['code'] == '200') {
            $body = $response['body'];
        }
        return ['content' => $body];
    }

    /**
     * @param string $message
     * @param string $email
     * @param string $ip
     * @return array
     */
    public function textCensorUserDefined($message, $email = '', $ip = '')
    {
        $data         = [];
        $data['text'] = $message;
        if (!empty($email)) {
            $data['userId'] = $email;
        }
        if (!empty($ip)) {
            $data['userIp'] = $ip;
        }
        return $this->request($this->textCensorUserDefinedUrl, $data);
    }
}
