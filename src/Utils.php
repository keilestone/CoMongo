<?php
namespace Wty\Mongodb;

class Utils
{

    /**
     * Utils constructor.
     */
    public function __construct()
    {

    }

    public static function saslprep(string $username): string
    {
        return str_replace(
            array(',', '='),
            array('=2C', '=3D'),
            $username
        );
    }

    public static function checkFlags(int $flag): bool
    {
        return $flag & 2;
    }

    public static function parseUrl($url)
    {
        $parsed = [];

        if(empty($url))
            return null;

        $url = trim($url);

        $url = preg_replace_callback('@#(.*)$@isu', function($match) use(&$parsed){
            $parsed['fragment'] = $match[1];
            return '';
        }, $url);


        $url = preg_replace_callback('@^([\w][\w\+\-\.]*):@isu', function($match) use(&$parsed){
            $parsed['scheme'] = $match[1];
            return '';
        }, $url);

        $location = '';
        $url = preg_replace_callback('@^//([^/]*)@isu', function($match) use(&$parsed, &$location) {
            $location = $match[1];
            return '';
        }, $url);


        $url = preg_replace_callback('@\?(.*)@isu', function($match) use(&$parsed){
            $parsed['query_string'] = $match[1];
            return '';
        }, $url);


        $url = preg_replace_callback('@\;(.*)@isu', function($match) use(&$parsed){
            $parsed['params'] = $match[1];
            return '';
        }, $url);


        if(!empty($url))
        {
            $url = preg_replace_callback('@^/([^/]*).*@isu', function($match) use(&$parsed){
                $parsed['database'] = $match[1];
                return '';
            }, $url);
        }

        if(!isset($parsed['database']) || empty($parsed['database']))
        {
            $parsed['database'] = 'admin';
        }

        if(empty($location))
            return $parsed;

        $location = preg_replace_callback('#^([^@]*)@#isu', function ($matches) use(&$parsed){
            $parsed['userinfo'] = $matches[1];
            return '';
        }, $location);

        $parsed['hosts'] = [];

        preg_replace_callback('@([^,]+)@isu', function ($matches) use(&$parsed){
            $pr = [
                'host' => 'localhost',
                'port' => 27017,
            ];

            $m = preg_replace_callback('@:([^:]*)$@isu', function($port) use(&$parsed, &$pr){
                $pr['port'] = $port[1];
                return '';
            }, $matches[1]);

            if(!empty($m))
                $pr['host'] = $m;

            array_push($parsed['hosts'], $pr);
        }, $location);

        if(empty($parsed['hosts']))
            $parsed['hosts'][] = [
                'host' => 'localhost',
                'port' => 27017
            ];

        $parsed['query'] = [];

        if(!empty($parsed['query_string']))
        {
            preg_replace_callback('@([^&]+)@isu', function($matches) use(&$parsed){
                preg_replace_callback('@([^=]*)=([^=]*)$@isu', function($p) use(&$parsed){
                    $parsed['query'][$p[1]] = $p[2];
                }, $matches[1]);
            }, $parsed['query_string']);
        }

        if(empty($parsed['userinfo']))
            return $parsed;

        $userinfo = $parsed['userinfo'];

        $userinfo = preg_replace_callback('@:([^:]*)$@isu', function($match) use(&$parsed){
            $parsed['password'] = $match[1];
            return '';
        }, $userinfo);

        $parsed['user'] = $userinfo;

        return $parsed;
    }

    /**
     * 判断数组是否为索引数组
     * @param array $arr
     * @return bool
     */
    public static function indexedArray(array $arr)
    {
        if (is_array($arr))
        {
            return count(array_filter(array_keys($arr), 'is_string')) === 0;
        }
        return false;
    }

    /**
     * 判断数组是否为连续的索引数组
     * 以下这种索引数组为非连续索引数组
     * [
     *   0 => 'a',
     *   2 => 'b',
     *   3 => 'c',
     *   5 => 'd',
     * ]
     * @param array $arr
     * @return bool
     */
    public static function continuousIndexedArray(array $arr)
    {
        if (is_array($arr))
        {
            $keys = array_keys($arr);

            return $keys == array_keys($keys);
        }

        return false;
    }

    /**
     * 判断数组是否为关联数组
     * @param array $arr
     * @return bool
     */
    public static function assocArray(array $arr)
    {
        if (is_array($arr))
        {
            return count(array_filter(array_keys($arr), 'is_string')) === count($arr);
        }

        return false;
    }

    /**
     * 判断数组是否为混合数组
     * @param array $arr
     * @return bool
     */
    public static function mixedArray(array $arr)
    {
        if (is_array($arr))
        {
            $count = count(array_filter(array_keys($arr), 'is_string'));

            return $count !== 0 && $count !== count($arr);
        }

        return false;
    }
}