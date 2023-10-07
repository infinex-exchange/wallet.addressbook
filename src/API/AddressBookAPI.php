<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;
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
                'symbolToNetId',
                [
                    'symbol' => $query['network'],
                    'allowDisabled' => true
                ]
            );
        else
            $promise = Promise\resolve(null);
        
        return $promise -> then(function($netid) use($th, $auth, $query) {
            $pag = new Pagination\Offset(50, 500, $query);
            $search = new Search(
                [
                    'name',
                    'address'
                ],
                $query
            );
            
            $task = [
                ':uid' => $auth['uid']
            ];
            $search -> updateTask($task);
            
            $sql = 'SELECT adbkid,
                           netid,
                           address,
                           name,
                           memo
                    FROM withdrawal_adbk
                    WHERE uid = :uid'
                 . $search -> sql()
                 .' ORDER BY adbkid ASC'
                 . $pag -> sql();
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            
            $adbk = [];
            $netids = [];
            
            while($row = $q -> fetch()) {
                if($pag -> iter()) break;
                $adbk[] = [
                    'adbkid' => $row['adbkid'],
                    'name' => $row['name'],
                    'address' => $row['address'],
                    'memo' => $row['memo']
                ];
                
                $netids[] = $row['netid'];
            }
            
            $promises = [];
            
            foreach($netids as $k => $v)
                $promises[] = $this -> amqp -> call(
                    'wallet.io',
                    'getNetwork',
                    [
                        'netid' => $v
                    ]
                ) -> then(
                    function($data) use(&$adbk, $k) {
                        $adbk[$k]['network'] = $data;
                    }
                );
            
            return Promise\all($promises) -> then(
                function() use(&$adbk, $pag) {
                    return [
                        'addresses' => $adbk,
                        'more' => $pag -> more
                    ];
                }
            );
        });
    }
    
    public function getAddress($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!$this -> validateAdbkid($path['adbkid']))
            throw new Error('VALIDATION_ERROR', 'adbkid', 400);
        
        $task = [
            ':uid' => $auth['uid'],
            ':adbkid' => $path['adbkid']
        ];
        
        $sql = 'SELECT adbkid,
                       netid,
                       address,
                       name,
                       memo
                FROM withdrawal_adbk
                WHERE uid = :uid
                AND adbkid = :adbkid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Address '.$path['adbkid'].' not found', 404);
            
        $adbk = [
            'adbkid' => $row['adbkid'],
            'name' => $row['name'],
            'address' => $row['address'],
            'memo' => $row['memo']
        ];

        return $this -> amqp -> call(
            'wallet.io',
            'getNetwork',
            [
                'netid' => $row['netid']
            ]
        ) -> then(
            function($data) use($adbk) {
                $adbk['network'] = $data;
                return $adbk;
            }
        );
    }
    
    public function deleteAddress($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!$this -> validateAdbkid($path['adbkid']))
            throw new Error('VALIDATION_ERROR', 'adbkid', 400);
        
        $task = [
            ':uid' => $auth['uid'],
            ':adbkid' => $path['adbkid']
        ];
        
        $sql = 'DELETE FROM withdrawal_adbk
                WHERE uid = :uid
                AND adbkid = :adbkid
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Address '.$path['adbkid'].' not found', 404);
    }
    
    public function editAddress($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!isset($body['name']))
            throw new Error('MISSING_DATA', 'name', 400);
        
        if(!$this -> validateAdbkid($path['adbkid']))
            throw new Error('VALIDATION_ERROR', 'adbkid', 400);
        if(!$this -> adbk -> validateAdbkName($body['name']))
            throw new Error('VALIDATION_ERROR', 'name', 400);
        
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':uid' => $auth['uid'],
            ':adbkid' => $path['adbkid'],
            ':name' => $body['name']
        ];
        
        $sql = 'UPDATE withrawal_adbk
                SET name = :name
                WHERE uid = :uid
                AND adbkid = :adbkid
                RETURNING netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Address '.$path['adbkid'].' not found', 404);
        }
        
        $task[':netid'] = $row['netid'];
        
        $sql = 'SELECT 1
                FROM withdrawal_adbk
                WHERE uid = :uid
                AND netid = :netid
                AND name = :name
                AND adbkid != :adbkid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if($row) {
            $this -> pdo -> rollBack();
            throw new Error('CONFLICT', 'Name already used in address book', 409);
        }
        
        $this -> pdo -> commit();
    }
    
    private function validateAdbkid($adbkid) {
        if(!is_int($adbkid)) return false;
        if($adbkid < 1) return false;
        return true;
    }
}

?>