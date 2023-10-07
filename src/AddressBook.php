<?php

use Infinex\Exceptions\Error;
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
            'saveAddress',
            function($body) use($th) {
                return $th -> saveAddress($body);
            }
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
        
        $promises[] = $this -> amqp -> unreg('saveAddress');
        
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
    
    public function saveAddress($body) {
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
    
    public function validateAdbkName($name) {
        return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $name);
    }
}

?>