<?php

namespace NS;

/**
 * 
 */
class NestedSet {

  /**
   * 
   * @var \PDO
   */
  private $pdo;
  private $table;
  private $keyColumn;
  private $treeColumn;
  private $parentColumn;
  private $leftColumn;
  private $rightColumn;
  public static $fetchMode = \PDO::FETCH_ASSOC;

  public function __construct(\PDO $pdo, $table, $keyColumn = 'id', $parentColumn = 'parent_id', $treeColumn = null, $leftColumn = 'lft', $rightColumn = 'rgt') {
    $this->pdo = $pdo;
    $this->table = $table;
    $this->keyColumn = $keyColumn;
    $this->treeColumn = $treeColumn;
    $this->parentColumn = $parentColumn;
    $this->leftColumn = $leftColumn;
    $this->rightColumn = $rightColumn;
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  }

  public function getTable() {
    return $this->table;
  }

  public function getKeyColumn() {
    return $this->keyColumn;
  }

  public function getTreeColumn() {
    return $this->treeColumn;
  }

  public function getParentColumn() {
    return $this->parentColumn;
  }

  public function getLeftColumn() {
    return $this->leftColumn;
  }

  public function getRightColumn() {
    return $this->rightColumn;
  }

  public function quoteIdent($ident) {
    return "`" . $ident . "`";
  }

  protected function fetch(\PDOStatement $stmt, $one = true) {
    switch (self::$fetchMode) {
      case \PDO::FETCH_ASSOC:
        if ($one) {
          return $stmt->fetch(\PDO::FETCH_ASSOC);
        } else {
          return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
      case \PDO::FETCH_CLASS:
        if ($one) {
          return $stmt->fetchObject('\NS\NestedSetNode', array($this));
        } else {
          return $stmt->fetchAll(\PDO::FETCH_CLASS, '\NS\NestedSetNode', array($this));
        }
    }
  }

  public function getParent($nodeId) {
    $sql = "SELECT parent . * 
            FROM  {$this->table} AS parent, {$this->table} AS node
            WHERE parent.{$this->keyColumn} = node.{$this->parentColumn}
              AND node.{$this->keyColumn} = :id";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array(':id' => $nodeId));
    return $this->fetch($stmt);
  }

  public function getDescendants($nodeId) {
    return $this->getTree($nodeId);
  }

  public function getChildren($nodeId) {
    $sql = "select node.* from {$this->table} as node"
        . "WHERE node.{$this->parentColumn} = :parent "
        . "ORDER BY node.lft";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array('parent' => $nodeId));

    return $this->fetch($stmt, false);
  }

  public function getTreeLeafs($treeId = null) {
    $sql = "SELECT * FROM {$this->table} WHERE {$this->rightColumn} = {$this->leftColumn} + 1";
    $params = array();
    if ($treeId != null && $this->treeColumn != null) {
      $sql .= " AND {$this->treeColumn} = :tree";
      $params = array(':tree' => $treeId);
    }
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $this->fetch($stmt, false);
  }

  public function getAncestors($nodeId) {

    $sql = "SELECT parent.*
            FROM {$this->table} AS node,{$this->table} AS parent
            WHERE node.lft BETWEEN parent.lft AND parent.rgt
              AND node.{$this->keyColumn} = :id
            ORDER BY node.lft";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array(':id' => $nodeId));
    return $this->fetch($stmt, false);
  }

  public function getTree($parentId) {

    // ,(COUNT(parent.{$this->keyColumn}) - 1) AS depth
    $sql = "SELECT node.*
				FROM {$this->table} AS node,
        			{$this->table} AS parent
                                WHERE node.{$this->leftColumn} BETWEEN parent.{$this->leftColumn} AND parent.{$this->rightColumn}
        			AND parent.{$this->keyColumn} = :parent
				ORDER BY node.{$this->leftColumn};";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array('parent' => $parentId));
    return $this->fetch($stmt, false);
  }

  public function getRoots() {
    $stmt = $this->pdo->prepare('select * from ' . $this->table . ' where ' . $this->parentColumn . ' is null or ' . $this->parentColumn . ' = 0');
    $stmt->execute();
    return $this->fetch($stmt, false);
  }

  public function getNodeId($node) {
    if (is_array($node)) {
      return $node[$this->keyColumn];
    } elseif (is_object($node)) {
      return $node->{$this->keyColumn};
    }
  }

  public function getNode($nodeId) {
    $stmt = $this->pdo->prepare('select * from ' . $this->table . ' where ' . $this->keyColumn . ' = :id');
    $stmt->execute(array(':id' => $nodeId));
    return $this->fetch($stmt);
  }

  public function createRoot($data, $treeId = null) {

    $data[$this->leftColumn] = 1;
    $data[$this->rightColumn] = 2;
    $data[$this->parentColumn] = 0;

    if (isset($this->treeColumn) && $treeId == null) {
      $sql = "select max({$this->treeColumn}) from {$this->table}";
      $treeId = $this->pdo->query($sql)->fetchColumn() + 1;
    }
    if ($treeId != null) {
      $data[$this->treeColumn] = $treeId;
    }
    $this->insert($data);

    return $this->pdo->lastInsertId();
  }

  public function createChild($parentId, $data, $treeId = null) {

    if ($this->treeColumn != null && $treeId == null) {
      throw new NestedSetException('Table ' . $this->table . ' can have many trees. You have to indicate tree explicity by passing tree id for column: ' . $this->treeColumn);
    }

    try {
      $this->pdo->beginTransaction();

      $this->pdo->exec('LOCK TABLE ' . $this->table . ' WRITE;');

      $sql = "select @myLeft := {$this->leftColumn} FROM {$this->table} WHERE {$this->keyColumn} = :parent";
      $params = array('parent' => $parentId);
      if ($treeId) {
        $sql .= " AND {$this->treeColumn} = :tree";
        $params['tree'] = $treeId;
        $data[$this->treeColumn] = $treeId;
      }
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      $leftValue = $stmt->fetchColumn();

      $this->pdo->exec("UPDATE {$this->table} SET {$this->rightColumn} = {$this->rightColumn} + 2 WHERE {$this->rightColumn} > @myLeft" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->table} SET {$this->leftColumn} = {$this->leftColumn} + 2 WHERE {$this->leftColumn} > @myLeft" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));

      $data[$this->leftColumn] = $leftValue + 1;
      $data[$this->rightColumn] = $leftValue + 2;
      $data[$this->parentColumn] = $parentId;

      $this->insert($data);

      $id = $this->pdo->lastInsertId();

      $this->pdo->exec("UNLOCK TABLES;");
      $this->pdo->commit();

      return $id;
    } catch (\PDOException $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  protected function insert($data) {
    $sql = 'INSERT INTO ' . $this->table . ' (' . implode(',', array_keys($data)) . ') VALUES (' .
        implode(',', array_map(function ($column) {
              return ':' . $column;
            }, array_keys($data))) . ')';

    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute($data);
  }

  public function countChildren($nodeId) {
    $sql = "select count(*) from {$this->table} where {$this->parentColumn} = :id";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array(':id' => $nodeId));
    return $stmt->fetchColumn();
  }

  public function hasChildren($nodeId) {
    return $this->countChildren($nodeId) > 0;
  }

  public function createSibling($siblingId, $data, $treeId = null) {

    if ($this->treeColumn != null && $treeId == null) {
      throw new NestedSetException('Table ' . $this->table . ' can have many trees. You have to indicate tree explicity by passing tree id for column: ' . $this->treeColumn);
    }

    try {
      $this->pdo->beginTransaction();

      $this->pdo->exec('LOCK TABLE ' . $this->table . ' WRITE;');

      $sql = "select @myRight := {$this->rightColumn}, {$this->parentColumn} FROM {$this->table} WHERE {$this->keyColumn} = :sibling";
      $params = array('sibling' => $siblingId);
      if ($treeId) {
        $sql .= " AND {$this->treeColumn} = :tree";
        $params['tree'] = $treeId;
        $data[$this->treeColumn] = $treeId;
      }
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      $rightValue = $stmt->fetchColumn();
      $parentId = $stmt->fetchColumn(1);

      $this->pdo->exec("UPDATE {$this->table} SET {$this->rightColumn} = {$this->rightColumn} + 2 WHERE {$this->rightColumn} > @myRight" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->table} SET {$this->leftColumn} = {$this->leftColumn} + 2 WHERE {$this->leftColumn} > @myRight" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));

      $data[$this->leftColumn] = $rightValue + 1;
      $data[$this->rightColumn] = $rightValue + 2;
      $data[$this->parentColumn] = $parentId;

      $this->insert($data);

      $id = $this->pdo->lastInsertId();

      $this->pdo->exec("UNLOCK TABLES;");
      $this->pdo->commit();

      return $id;
    } catch (\PDOException $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  public function deleteNode($nodeId) {
    try {
      $this->pdo->beginTransaction();
      $this->pdo->exec('LOCK TABLE ' . $this->table . ' WRITE;');

      $sql = 'SELECT ';
      if ($this->treeColumn) {
        $sql .= $this->treeColumn . ',';
      }

      $sql .= " @myLeft := {$this->leftColumn}, @myRight := {$this->leftColumn}, @myWidth := {$this->rightColumn} - {$this->leftColumn} + 1 FROM {$this->table} WHERE {$this->keyColumn} = :node";
      $params = array('node' => $nodeId);
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      $treeId = $stmt->fetchColumn();

      $sqlDelete = "DELETE FROM {$this->table} WHERE {$this->leftColumn} = @myLeft";
      if ($treeId) {
        $sqlDelete .= " AND {$this->treeColumn} = " . $treeId;
      }
      $this->pdo->exec($sqlDelete);

      $this->pdo->exec("UPDATE {$this->table} SET {$this->rightColumn} = {$this->rightColumn} - 1, {$this->leftColumn} = {$this->leftColumn} - 1 WHERE {$this->leftColumn} BETWEEN @myLeft AND @myRight" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->table} SET {$this->rightColumn} = {$this->rightColumn} - 2 WHERE {$this->rightColumn} > @myRight" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->table} SET {$this->leftColumn} = {$this->leftColumn} - 2 WHERE {$this->leftColumn} > @myRight" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));

      $this->pdo->exec("UNLOCK TABLES;");
      $this->pdo->commit();
    } catch (\PDOException $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  public function deleteNodeAndChildren($nodeId) {
    try {
      $this->pdo->beginTransaction();
      $this->pdo->exec('LOCK TABLE ' . $this->table . ' WRITE;');

      $sql = 'SELECT ';
      if ($this->treeColumn) {
        $sql .= $this->treeColumn . ',';
      }

      $sql .= " @myLeft := {$this->leftColumn}, @myRight := {$this->leftColumn}, @myWidth := {$this->rightColumn} - {$this->leftColumn} + 1 FROM {$this->table} WHERE {$this->keyColumn} = :node";
      $params = array('node' => $nodeId);
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      $treeId = $stmt->fetchColumn();

      $sqlDelete = "DELETE FROM {$this->table} WHERE {$this->leftColumn} BETWEEN @myLeft AND @myRight";
      if ($treeId) {
        $sqlDelete .= " AND {$this->treeColumn} = " . $treeId;
      }
      $this->pdo->exec($sqlDelete);

      $this->pdo->exec("UPDATE {$this->table} SET {$this->rightColumn} = {$this->rightColumn} - @myWidth WHERE {$this->rightColumn} > @myRight" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->table} SET {$this->leftColumn} = {$this->leftColumn} - @myWidth WHERE {$this->leftColumn} > @myRight" . (isset($treeId) ? " AND {$this->treeColumn} = " . $treeId : ''));

      $this->pdo->exec("UNLOCK TABLES;");
      $this->pdo->commit();
    } catch (\PDOException $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  public function deleteTree($treeId) {
    $sql = "DELETE FROM {$this->table} WHERE {$this->treeColumn} = :tree";
    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute(array(':tree' => $treeId));
  }

}

class NestedSetUtils {

  public static function hierarchize($collection) {
    // TODO:
  }

  public function treeze($array, $level = 0, $childrenKey = null) {
    // TODO:
  }

}

class NestedSetException extends \Exception {}

class NestedSetNode {

  private $nestedSet;

  public function __construct(NestedSet $ns) {
    $this->nestedSet = $ns;
  }

  public static function fromArray($array, NestedSet $ns) {
    $obj = new NestedSetNode($ns);
    foreach ($array as $key => $value) {
      $obj->{$key} = $value;
    }
    return $obj;
  }

  public function isRoot() {
    return $this->{$this->nestedSet->getParentColumn()} > 0;
  }

  public function getKey() {
    return $this->nestedSet->getNodeId($this);
  }
  
  public function getTreeId() {
    return @$this->{$this->nestedSet->getTreeColumn()};
  }

  public function getDescendants() {
    return $this->nestedSet->getDescendants($this->getKey());
  }

  public function getChildren() {
    return $this->nestedSet->getChildren($this->getKey());
  }

  public function addChild($data) {
    return $this->nestedSet->createChild($this->getKey(), $data, $this->getTreeId());
  }

  public function addSibling($data) {
    return $this->nestedSet->createSibling($this->getKey(), $data, $this->getTreeId());
  }

  public function toArray($deep = false) {
    return (array) $this;
  }

}
