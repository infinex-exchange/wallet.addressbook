<?php

use Infinex\Exceptions\Error;
use React\Promise;

class AddressBookAPI {
    private $log;
    private $amqp;
    private $pdo;
    private $adbk;
    
    function __construct($log, $amqp, $pdo, $adbk) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> adbk = $adbk;
        
        $this -> log -> debug('Initialized address book API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/', [$this, 'getAllAddresses']);
        $rc -> get('/{adbkid}', [$this, 'getAddress']);
        $rc -> patch('/{adbkid}', [$this, 'editAddress']);
        $rc -> delete('/{adbkid}', [$this, 'deleteAddress']);
    }
    
    public function getAllAddresses($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(isset($query['network']))
            $promise = $this -> amqp -> call(
                'wallet.io',
                'getNetwork',
                [ 'symbol' => $query['network'] ]
            );
        else
            $promise = Promise\resolve(null);
        
        return $promise -> then(function($network) use($th, $auth, $query) {
            if($network && !$network['enabled'])
                throw new Error('FORBIDDEN', 'Network '.$query['network'].' is out of service', 403);
            
            $resp = $this -> adbk -> getAddresses([
                'uid' => $auth['uid'],
                'netid' => @$network['netid'],
                'offset' => @$query['offset'],
                'limit' => @$query['limit'],
                'q' => @$query['q']
            ]);
            
            $promises = [];
            $mapNetworks = [];
            
            foreach($resp['addresses'] as $record) {
                $netid = $record['netid'];
                
                if(!array_key_exists($netid, $mapNetworks)) {
                    $mapNetworks[$netid] = null;
                    
                    $promises[] = $th -> amqp -> call(
                        'wallet.io',
                        'getNetwork',
                        [ 'netid' => $netid ]
                    ) -> then(
                        function($network) use(&$mapNetworks, $netid) {
                            $mapNetworks[$netid] = $network['symbol'];
                        }
                    );
                }
            }
            
            return Promise\all($promises) -> then(
                function() use(&$mapNetworks, $resp, $th) {
                    foreach($resp['addresses'] as $k => $v)
                        $resp['addresses'][$k] = $th -> ptpAddress($v, $mapNetworks[ $v['netid'] ]);
                    
                    return $resp;
                }
            );
        });
    }
    
    public function getAddress($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $address = $this -> adbk -> getAddress([
            'adbkid' => $path['adbkid']
        ]);
        
        if($address['uid'] != $auth['uid'])
            throw new Error('FORBIDDEN', 'No permissions to address '.$path['adbkid'], 403);
        
        return $this -> amqp -> call(
            'wallet.io',
            'getNetwork',
            [ 'netid' => $address['netid'] ]
        ) -> then(
            function($network) use($th, $address) {
                return $th -> ptpAddress($address, $network['symbol']);
            }
        );
    }
    
    public function deleteAddress($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $address = $this -> adbk -> getAddress([
            'adbkid' => $path['adbkid']
        ]);
        
        if($address['uid'] != $auth['uid'])
            throw new Error('FORBIDDEN', 'No permissions to address '.$path['adbkid'], 403);
        
        $this -> adbk -> deleteAddress([
            'adbkid' => $path['adbkid']
        ]);
    }
    
    public function editAddress($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $address = $this -> adbk -> getAddress([
            'adbkid' => $path['adbkid']
        ]);
        
        if($address['uid'] != $auth['uid'])
            throw new Error('FORBIDDEN', 'No permissions to address '.$path['adbkid'], 403);
        
        $this -> adbk -> editAddress([
            'adbkid' => $path['adbkid'],
            'name' => @$body['name']
        ]);
        
        return $this -> getAddress($path, [], [], $auth);
    }
}

?>