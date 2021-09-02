<?php
namespace Wty\Mongodb\Authenticate;

use MongoDB\BSON\Binary;
use Wty\Mongodb\Exceptions\AuthException;
use Wty\Mongodb\Connection;
use Wty\Mongodb\Utils;

class Scram
{
    private Connection $con;
    private string $username;
    private string $password;
    private string $db;

    public function __construct(Connection $con, string $db, string $username, string $password)
    {
        $this->con = $con;
        $this->db = $db;
        $this->username = $username;
        $this->password = $password;
    }

    public function auth($mechanism): bool
    {
        $method = [
            'SCRAM-SHA-256' => 'sha256',
            'SCRAM-SHA-1' => 'sha1'
        ];

        $nonce = bin2hex(random_bytes(8));

        $bareMsg= 'n=' . $this->username . ',r=' . $nonce;

        $firstMsg = base64_encode('n,,' . $bareMsg);

        $rs = $this->con->runCmd($this->db, [
            'saslStart' => 1
        ], [
            'mechanism' => $mechanism,
            'autoAuthorize' => 1,
            'payload' =>  $firstMsg,
        ]);


        if(is_null($rs))
        {
            throw new AuthException($this->con->getError());
        }

//        var_dump($rs);

        $doc = $rs->getFirstDoc();

        $conversationId = $doc->conversationId;
        $serverFirst = base64_decode($doc->payload);

        preg_match_all('@(\w+)=([^,]*)@isu', $serverFirst, $matches);

        $parsed = [];

        if(!empty($matches))
        {
            foreach ($matches[0] as $k => $match)
            {
                $parsed[$matches[1][$k]] = $matches[2][$k];
            }
        }

        $iterations = intval($parsed['i']);
        $salt = $parsed['s'];
        $serverNonce = $parsed['r'];

        if(substr($serverNonce, 0, 16) != $nonce)
        {
            throw new AuthException('Server returned an invalid nonce.');
        }


//        data = saslprep(credentials.password).encode("utf-8")


        if($mechanism == 'SCRAM-SHA-256')
            $passwordS = hash_pbkdf2($method[$mechanism], Utils::saslprep($this->password),
                base64_decode($salt), $iterations, 0, true);
        else
        {
            $passwordS = hash_pbkdf2($method[$mechanism], md5($this->username . ":mongo:" . $this->password),
                base64_decode($salt), $iterations, 20, true);
        }


        $keyC = hash_hmac($method[$mechanism], 'Client Key', $passwordS, true);

        $final = 'c=biws,r=' . $serverNonce;
        $auth = $bareMsg . ',' .  $serverFirst . ',' . $final;

        $p = base64_encode($keyC ^ hash_hmac($method[$mechanism], $auth, hash($method[$mechanism], $keyC, true), true));

        $serverKey = hash_hmac($method[$mechanism], 'Server Key', $passwordS, true);
        $serverSig = base64_encode(hash_hmac($method[$mechanism], $auth, $serverKey, true));

        $rs = $this->con->runCmd($this->db, [
            'saslContinue' => 1,
        ], [
            'conversationId' => $conversationId,
            'payload' => base64_encode($final . ',p=' . $p)
        ]);

        if(is_null($rs))
        {
            throw new AuthException($this->con->getError());
        }

        $doc = $rs->getFirstDoc();

        $payload = base64_decode($doc->payload);

        preg_match_all('@(\w+)=([^,]*)@isu', $payload, $matches);

        $parsed = [];

        if(!empty($matches))
        {
            foreach ($matches[0] as $k => $match)
            {
                $parsed[$matches[1][$k]] = $matches[2][$k];
            }
        }

        if(!isset($parsed['v']) || $parsed['v'] != $serverSig)
        {
            throw new AuthException('Server returned an invalid signature.');
        }

        if(!$doc->done)
        {
            $rs = $this->con->runCmd($this->db, [
                'saslContinue' => 1,
            ], [
                'conversationId' => $conversationId,
                'payload' => base64_encode('')
            ]);

            if(is_null($rs))
            {
                throw new AuthException($this->con->getError());
            }

            $doc = $rs->getFirstDoc();

            if($doc->done == false)
            {
                throw new AuthException( 'failed to authenticate');
            }

            return true;
        }

        return true;
    }
}