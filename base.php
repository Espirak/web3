<?php
include 'vendor/autoload.php';

use Web3\Web3;
use Web3\Utils;
use Web3\Contract;

class Base
{
    private $web3;

    private $contract;

    private $decimals;

    private $contractAddress;

    public function __construct()
    {
        $decimals = 4;
        $provider = "http://107.183.242.178:9982";
        $this->web3 = new Web3($provider);
        $this->decimals = str_pad(1, $decimals + 1, 0);
        $this->contract = new Contract($provider, file_get_contents('abi.json'));
        $this->contractAddress = '0x79B3138103C5b389F1BA5113120e31FF2e799446';
    }

    public function validateAddress($address)
    {
        return Utils::isAddress($address);
    }

    public function getAddress()
    {
        $this->web3->eth->accounts(function ($err, $accounts) {
            die(json_encode($accounts));
        });
    }

    public function gasPrice()
    {
        $gasPrice = 0;
        $this->web3->eth->gasPrice(function ($err, $result) use (&$gasPrice) {
            if (isset($result)) {
                $gasPrice = $result->toString();
            } else {
                $this->gasPrice();
            }
        });
        return $gasPrice;
    }

    public function newAccount()
    {
        $password = $_POST['password'];

        if (empty($password))
            die(json_encode([
                'status' => 0,
                'msg' => '密码不能为空'
            ]));

        $this->web3->personal->newAccount($password, function ($err, $account) {
            die(json_encode([
                'status' => 1,
                'msg' => '操作成功'
            ]));
        });
    }

    public function unlockAccount($address, $password)
    {
        $this->web3->personal->unlockAccount($address, $password, function ($err, $result) {
            if ($err !== null) {
                if (!strstr($err->getMessage(), 'cURL'))
                    die(json_encode([
                        'status' => 0,
                        'msg' => $err->getMessage()
                    ]));
            }
        });
    }

    public function estimateGas($functionName, $fromAccount, $toAccount, $money)
    {
        $gas = 0;

        // 预测Gas
        $this->contract->at($this->contractAddress)->estimateGas($functionName, $toAccount, $money, [
            'from' => $fromAccount,
        ], function ($err, $result) use (&$gas, $functionName, $fromAccount, $toAccount, $money) {
            if (isset($result)) {
                $gas = $result->toString();
            } else {
                $this->estimateGas($functionName, $fromAccount, $toAccount, $money);
            }
        });

        return $gas;
    }

    public function getTokenBalance($address)
    {
        $balance = 0;
        $this->contract->at($this->contractAddress)->call('balanceOf', $address, function ($err, $result) use (&$balance) {
            if (isset($result)) {
                $balance = $result['balance']->toString();
            }
        });
        return $balance;
    }

    public function getBalance($address)
    {
        $amount = 0;
        // get balance
        $this->web3->eth->getBalance($address, function ($err, $balance) use(&$amount) {
            if (isset($balance)) {
                $amount = $balance->toString();
            }
        });
        return $amount;
    }

    public function transaction()
    {
        $money = $_POST['number'];
        $password = $_POST['password'];
        $fromAccount = $_POST['fromAddress'];
        $toAccount = $_POST['address'];

        $balance = $this->getBalance($fromAccount);

        if ($money > $balance)
            die(json_encode([
                'status' => 0,
                'msg' => '账户余额不足'
            ]));

        if (empty($password))
            die(json_encode([
                'status' => 0,
                'msg' => '密码不能为空'
            ]));

        if (!$this->validateAddress($fromAccount))
            die(json_encode([
                'status' => 0,
                'msg' => '付款地址不是一个以太坊地址'
            ]));

        if (!$this->validateAddress($toAccount))
            die(json_encode([
                'status' => 0,
                'msg' => '转出地址不是一个以太坊地址'
            ]));

//        die();
        $this->unlockAccount($fromAccount, $password);

        // send transaction
        $this->web3->eth->sendTransaction([
            'from' => $fromAccount,
            'to' => $toAccount,
            'value' => "0x" . dechex($money)
        ], function ($err, $result) use ($fromAccount, $toAccount) {
            if ($err !== null) {
                if (!strstr($err->getMessage(), 'cURL'))
                    die(json_encode([
                        'status' => 0,
                        'msg' => $err->getMessage()
                    ]));
            }
            if ($result) {
                die(json_encode([
                    'status' => 1,
                    'data' => $result
                ]));
            }
        });
    }

    public function smartContractTransaction()
    {
        $money = $_POST['number'];
        $password = $_POST['password'];
//        $gasPrice = "0x" . dechex(Utils::toWei(40, "Gwei")->toString());
        $fromAccount = $_POST['fromAddress'];
        $toAccount = $_POST['address'];

        $balance = $this->getTokenBalance($fromAccount);

        if ($money > $balance)
            die(json_encode([
                'status' => 0,
                'msg' => '账户余额不足'
            ]));

        if (empty($password))
            die(json_encode([
                'status' => 0,
                'msg' => '密码不能为空'
            ]));

        if (!$this->validateAddress($fromAccount))
            die(json_encode([
                'status' => 0,
                'msg' => '付款地址不是一个以太坊地址'
            ]));

        if (!$this->validateAddress($toAccount))
            die(json_encode([
                'status' => 0,
                'msg' => '转出地址不是一个以太坊地址'
            ]));

        if (!isset($gasPrice)) {
            $gasPrice = "0x" . dechex($this->gasPrice());
        }

        $this->unlockAccount($fromAccount, $password);

        $gas = $this->estimateGas("transfer", $fromAccount, $toAccount, $money);

        $gas = "0x" . dechex($gas);

        $this->contract->at($this->contractAddress)->send('transfer', $toAccount, $money, [
            'from' => $fromAccount,
            'gas' => $gas,
            'gasPrice' => $gasPrice
        ], function ($err, $result) {
            if ($err !== null) {
                if (!strstr($err->getMessage(), 'cURL'))
                    die(json_encode([
                        'status' => 0,
                        'msg' => $err->getMessage()
                    ]));
            }
            if ($result) {
                die(json_encode([
                    'status' => 1,
                    'data' => $result
                ]));
            }
        });
    }

    public function listen(){
        $type = $_POST['type'];
        $address = $_POST['address'];

        if($type == 'eth'){
            $decimals = 1000000000000000000;
            $res = $this->listenEth($address);
        }else{
            $decimals = $this->decimals;
            $res = $this->listenSmartContract($address);
        }

        die(json_encode([
            'status' => 1,
            'ehter' => Utils::UNITS['ether'],
            'gwei' => Utils::UNITS['gwei'],
            'decimals' => $decimals,
            'data' => $res
        ]));
    }

    public function listenEth($address){
        $api = "https://api.etherscan.io/api?module=account&action=txlist&address=$address&page=1&offset=10&sort=desc";
        $res = $this->getList($api);
        return $res['result'];
    }

    public function listenSmartContract($address){
        $api = "https://api.etherscan.io/api?module=account&action=tokentx&contractaddress={$this->contractAddress}&address=$address&page=1&offset=10&sort=desc";
        $res = $this->getList($api);
        return $res['result'];
    }

    private function getList($url){
        return json_decode(file_get_contents($url), true);
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $b = new Base();
        $action = $_POST['action'];
        $b->$action();
    }
}
