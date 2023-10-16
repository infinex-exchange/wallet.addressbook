<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;
use function Infinex\Validation\validateId;
use React\Promise;

class AddressBook {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized address book');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'createAddress',
            [$this, 'createAddress']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started address book');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start address book: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('createAddress');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped address book');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop address book: '.((string) $e));
            }
        );
    }
    
    public function getAddresses($body) {
        if(isset($body['uid']) && !validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(isset($body['netid']) && !is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
            
        $pag = new Pagination\Offset(50, 500, $query);
        $search = new Search(
            [
                'name',
                'address'
            ],
            $query
        );
            
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT adbkid,
                       netid,
                       address,
                       name,
                       memo
                FROM withdrawal_adbk';
        
        if(
            
        $sql .= $search -> sql()
             .' ORDER BY adbkid ASC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
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
            
            if($netid) {
                for($i = 0; $i < count($adbk); $i++)
                    $adbk[$i]['network'] = $query['network'];
                
                return [
                    'addresses' => $adbk,
                    'more' => $pag -> more
                ];
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
        
        $sql = 'UPDATE withdrawal_adbk
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
    
    public function createAddress($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid', 400);
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid', 400);
        if(!isset($body['address']))
            throw new Error('MISSING_DATA', 'address', 400);
        if(!isset($body['name']))
            throw new Error('MISSING_DATA', 'name', 400);
        
        if(!$this -> validateAdbkName($body['name']))
            throw new Error('VALIDATION_ERROR', 'name', 400);
        
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':uid' => $body['uid'],
            ':netid' => $body['netid'],
            ':address' => $body['address'],
            ':name' => $body['name'],
            ':memo' => isset($body['memo']) ? $body['memo'] : null
        );
        
        $sql = 'SELECT name
                FROM withdrawal_adbk
                WHERE uid = :uid
                AND netid = :netid
                AND (
                    name = :name
                    OR (
                        address = :address
                        AND memo IS NOT DISTINCT FROM :memo
                    )
                )
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if($row) {
            $pdo -> rollBack();
            
            if($row['name'] == $body['name'])
                throw new Error('CONFLICT', 'Name already used in address book', 409);
            
            throw new Error('CONFLICT', 'Address already stored as "'.$row['name'].'"', 409);
        }
        
        $sql = 'INSERT INTO withdrawal_adbk(
                uid,
                netid,
                address,
                name,
                memo
            ) VALUES (
                :uid,
                :netid,
                :address,
                :name,
                :memo
            )';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
    }
    
    private function validateAdbkid($adbkid) {
        if(!is_int($adbkid)) return false;
        if($adbkid < 1) return false;
        return true;
    }
    
    private function validateAdbkName($name) {
        return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $name);
    }
}

?>