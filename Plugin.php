<?php

/**
 * 在 Typecho 中加入百度文本内容审核，过滤评论中的敏感内容；<br/> 支持请在 <a href="https://github.com/sy-records/ty-baidu-textcensor" target="_blank">GitHub</a> 点个 Star，Watch 关注更新。
 *
 * @package BaiduTextCensor
 * @author 沈唁
 * @version 1.1.0
 * @link https://qq52o.me
 */
class BaiduTextCensor_Plugin implements Typecho_Plugin_Interface
{
    /**
     * @return mixed
     */
    public static function activate()
    {
        $error = null;
        $runtime_dir = dirname(__FILE__) . '/runtime/';
        if ((!is_dir($runtime_dir) || !is_writeable($runtime_dir))) {
            $error = '<br /><strong>' . _t('%s 目录不可写, 可能会导致无法保存Access Token', $runtime_dir) . '</strong>';
        }
        if (!function_exists('curl_init')) {
            $error = '<br /><strong>' . _t('此插件需要开启 curl 扩展') . '</strong>';
        }
        Typecho_Plugin::factory('Widget_Feedback')->comment = [__CLASS__, 'checkComment'];
        Typecho_Plugin::factory('Widget_Feedback')->trackback = [__CLASS__, 'checkComment'];
        Typecho_Plugin::factory('Widget_XmlRpc')->pingback = [__CLASS__, 'checkComment'];
        return _t('请在插件设置里设置百度内容审核的信息，以使插件正常使用！') . $error;
    }

    /**
     * @return mixed
     */
    public static function deactivate()
    {
        return _t('BaiduTextCensor 插件已禁用成功！');
    }

    /**
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $app_id = new Typecho_Widget_Helper_Form_Element_Text('app_id', null, null, _t('AppID(*)：'));
        $form->addInput($app_id->addRule('required', _t('AppID不能为空！')));

        $api_key = new Typecho_Widget_Helper_Form_Element_Text('api_key', null, null, _t('API Key(*)：'));
        $form->addInput($api_key->addRule('required', _t('API Key不能为空！')));

        $secret_key = new Typecho_Widget_Helper_Form_Element_Text('secret_key', null, null, _t('Secret Key(*)：'), _t("AppID、API Key、Secret Key在百度 AI 控制台的 <a href='https://console.bce.baidu.com/ai/?fromai=1#/ai/antiporn/app/list' target='_blank'>产品服务 / 内容审核 - 应用列表</a> 创建应用后获取；"));
        $form->addInput($secret_key->addRule('required', _t('Secret Key不能为空！')));

        $is_check_admin = new Typecho_Widget_Helper_Form_Element_Radio('is_check_admin', ['否', '是'], '0', _t('是否验证管理员：'), _t('选择“否”将会跳过管理员的评论内容，不去验证！'));
        $form->addInput($is_check_admin);
    }

    /**
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 过滤评论
     */
    public static function checkComment($comment, $post)
    {
        $options = Typecho_Widget::widget('Widget_Options');

        $app_id = $options->plugin('BaiduTextCensor')->app_id;
        $api_key = $options->plugin('BaiduTextCensor')->api_key;
        $secret_key = $options->plugin('BaiduTextCensor')->secret_key;

        $is_check_admin = $options->plugin('BaiduTextCensor')->is_check_admin;
        if (!$is_check_admin) {
            $userObj = Typecho_Widget::widget('Widget_User');
            if($userObj->hasLogin() && $userObj->pass('administrator', true)) {
                return $comment;
            }
        }

        if (!class_exists('Luffy\TextCensor\AipBase')) {
            require_once 'AipBase.php';
        }
        $client = new Luffy\TextCensor\AipBase($app_id, $api_key, $secret_key);
        $res = $client->textCensorUserDefined("{$comment['author']}：{$comment['text']}", $comment['mail'], $comment['ip']);

        if (isset($res['error_code'])) {
            $comment['status'] = 'waiting';
            goto delete;
        }

        if ($res['conclusionType'] == 2) {
            Typecho_Cookie::set('__typecho_remember_text', $comment['text']);
            throw new Typecho_Widget_Exception("评论内容" . $res['data'][0]['msg'] . "，请重新评论");
        } elseif (in_array($res['conclusionType'], [3, 4])) {
            // 疑似和失败的写数据库，人工审核
            $comment['status'] = 'waiting';
        }

        delete:
        Typecho_Cookie::delete('__typecho_remember_text');
        return $comment;
    }
}
