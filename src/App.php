<?php

require __DIR__.'/AddressBook.php';

require __DIR__.'/API/AddressBookAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $adbk;
    
    private $adbkApi;
    private $rest;
    
    function __construct() {
        parent::__construct('wallet.addressbook');
        
        $this -> pdo = new Infinex\Database\PDO(
            $this -> loop,
            $this -> log,
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        $this -> adbk = new AddressBook(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> adbkApi = new AddressBookAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> adbk
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> adbkApi
            ]
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> pdo -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> adbk -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $this -> rest -> stop() -> then(
            function() use($th) {
                return $th -> adbk -> stop();
            }
        ) -> then(
            function() use($th) {
                return $th -> pdo -> stop();
            }
        ) -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>