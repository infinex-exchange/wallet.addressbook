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
            'getAddresses',
            [$this, 'getAddresses']
        );
        
        $promises[] = $this -> amqp -> method(
            'getAddress',
            [$this, 'getAddress']
        );
        
        $promises[] = $this -> amqp -> method(
            'deleteAddress',
            [$this, 'deleteAddress']
        );
        
        $promises[] = $this -> amqp -> method(
            'editAddress',
            [$this, 'editAddress']
        );
        
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
        
        $promises[] = $this -> amqp -> unreg('getAddresses');
        $promises[] = $this -> amqp -> unreg('getAddress');
        $promises[] = $this -> amqp -> unreg('deleteAddress');
        $promises[] = $this -> amqp -> unreg('editAddress');
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
            
        $pag = new Pagination\Offset(50, 500, $body);
        $search = new Search(
            [
                'name',
                'address'
            ],
            $body
        );
            
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT adbkid,
                       uid,
                       netid,
                       address,
                       name,
                       memo
                FROM withdrawal_adbk
                WHERE 1=1';
        
        if(isset($body['uid'])) {
            $task[':uid'] = $body['uid'];
            $sql .= ' AND uid = :uid';
        }
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' AND netid = :netid';
        }
            
        $sql .= $search -> sql()
             .' ORDER BY adbkid ASC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $adbk = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $adbk[] = $this -> rtrAddress($row);
        }
            
        return [
            'addresses' => $adbk,
            'more' => $pag -> more
        ];
    }
    
    public function getAddress($body) {
        if(!isset($body['adbkid']))
            throw new Error('MISSING_DATA', 'adbkid', 400);
        
        if(!validateId($body['adbkid']))
            throw new Error('VALIDATION_ERROR', 'adbkid', 400);
        
        $task = [
            ':adbkid' => $body['adbkid']
        ];
        
        $sql = 'SELECT adbkid,
                       uid,
                       netid,
                       address,
                       name,
                       memo
                FROM withdrawal_adbk
                WHERE adbkid = :adbkid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Address '.$body['adbkid'].' not found', 404);
            
        return $this -> rtrAddress($row);
    }
    
    public function deleteAddress($body) {
        if(!isset($body['adbkid']))
            throw new Error('MISSING_DATA', 'adbkid', 400);
        
        if(!validateId($body['adbkid']))
            throw new Error('VALIDATION_ERROR', 'adbkid', 400);
        
        $task = [
            ':adbkid' => $body['adbkid']
        ];
        
        $sql = 'DELETE FROM withdrawal_adbk
                WHERE adbkid = :adbkid
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Address '.$body['adbkid'].' not found', 404);
    }
    
    public function editAddress($body) {
        if(!isset($body['adbkid']))
            throw new Error('MISSING_DATA', 'adbkid', 400);
        if(!isset($body['name']))
            throw new Error('MISSING_DATA', 'name', 400);
        
        if(!validateId($body['adbkid']))
            throw new Error('VALIDATION_ERROR', 'adbkid', 400);
        if(!$this -> validateAdbkName($body['name']))
            throw new Error('VALIDATION_ERROR', 'name', 400);
        
        // Update
        $task = [
            ':adbkid' => $body['adbkid'],
            ':name' => $body['name']
        ];
        
        $sql = 'UPDATE withdrawal_adbk
                SET name = :name
                WHERE adbkid = :adbkid
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        try {
            $q -> execute($task);
        } catch(\PDOException $e) {
            if($e -> getCode() != 23505) throw $e;
            throw new Error('CONFLICT', 'Address with this name already exists', 409);
        }
        
        $row = $q -> fetch();
        if(!$row)
            throw new Error('NOT_FOUND', 'Address '.$body['adbkid'].' not found', 404);
    }
    
    public function createAddress($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        if(!isset($body['address']))
            throw new Error('MISSING_DATA', 'address');
        if(!isset($body['name']))
            throw new Error('MISSING_DATA', 'name', 400);
        
        if(!validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(!is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        if(!is_string($body['address']))
            throw new Error('VALIDATION_ERROR', 'address');
        if(!$this -> validateAdbkName($body['name']))
            throw new Error('VALIDATION_ERROR', 'name', 400);
        
        if(isset($body['memo']) && !is_string($body['memo']))
            throw new Error('VALIDATION_ERROR', 'memo');
        
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
            )
            ON CONFLICT DO NOTHING
            RETURNING adbkid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('CONFLICT', 'Address or name already used in addressbook', 409);
        
        return [
            'adbkid' => $row['adbkid']
        ];
    }
    
    private function validateAdbkName($name) {
        return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $name);
    }
    
    private function rtrAddress($row) {
        return [
            'adbkid' => $row['adbkid'],
            'uid' => $row['uid'],
            'netid' => $row['netid'],
            'name' => $row['name'],
            'address' => $row['address'],
            'memo' => $row['memo']
        ];
    }
}

?>