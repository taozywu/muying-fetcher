<?php

/**
 * 数据库连接类
 * @author taozywu
 * @date 2013/09/18
 */
class DoMySQL
{

    private $connection ;

    public function __construct( $hostname , $username , $password , $database , $charset = "utf8" )
    {
        if( !$this->connection = mysql_connect( $hostname , $username , $password ) )
        {
            exit( 'Error: Could not make a database connection using ' . $username . '@' . $hostname ) ;
        }

        if( !mysql_select_db( $database , $this->connection ) )
        {
            exit( 'Error: Could not connect to database ' . $database ) ;
        }

        mysql_query( "SET NAMES '{$charset}'" , $this->connection ) ;
    }

    /**
     * 解析sql
     * @param type $sql
     * @return type
     */
    function query( $sql )
    {
        $res = mysql_query( $sql , $this->connection ) ;

        return $res && is_resource( $res ) ? $res : false ;
    }

    /**
     * 开启事务
     * @return type
     */
    public function beginTransaction() {
        return $this->query('START TRANSACTION');
    }
    
    /**
     * 事务提交
     * @return type
     */
    public function commit() {
        return $this->query('COMMIT');
    }
    
    /**
     * 事务回滚
     * @return type
     */
    public function rollBack() {
        return $this->query('ROLLBACK');
    }
        
    /**
     * 获取某个字段的值
     * @param type $sql
     * @param type $limited
     * @return boolean
     */
    function getOne( $sql , $limited = false )
    {
        if( $limited == true )
        {
            $sql = trim( $sql . ' LIMIT 1' ) ;
        }

        $res = $this->query( $sql ) ;
        if( $res !== false )
        {
            $row = mysql_fetch_row( $res ) ;

            return isset( $row[ 0 ] ) ? $row[ 0 ] : false ;
        }
        else
        {
            return false ;
        }
    }

    /**
     * 获取所有值
     */
    function getAll( $sql )
    {
        $res = $this->query( $sql ) ;
        if( $res !== false )
        {
            $arr = array( ) ;
            while( $row = mysql_fetch_assoc( $res ) )
            {
                $arr[ ] = $row ;
            }

            return $arr ;
        }
        else
        {
            return false ;
        }
    }

    /**
     * 获取某一行值
     * @param type $sql
     * @param type $limited
     * @return boolean
     */
    function getRow( $sql , $limited = false )
    {
        if( $limited == true )
        {
            $sql = trim( $sql . ' LIMIT 1' ) ;
        }

        $res = $this->query( $sql ) ;
        if( $res !== false )
        {
            return mysql_fetch_assoc( $res ) ;
        }
        else
        {
            return false ;
        }
    }

    /**
     * 获取某一列值
     * @param type $sql
     * @return boolean
     */
    function getCol( $sql )
    {
        $res = $this->query( $sql ) ;
        if( $res !== false )
        {
            $arr = array( ) ;
            while( $row = mysql_fetch_row( $res ) )
            {
                $arr[ ] = $row[ 0 ] ;
            }

            return $arr ;
        }
        else
        {
            return false ;
        }
    }

    /**
     * 获取刚执行后最新的ID
     * @return type
     */
    function insertId()
    {
        return mysql_insert_id( $this->connection ) ;
    }

    /**
     * 获取总行数
     * @param type $query
     * @return type
     */
    function numRows( $query )
    {
        return mysql_num_rows( $query ) ;
    }

}
