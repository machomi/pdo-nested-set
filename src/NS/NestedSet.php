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
    $tree = $this->getTree($nodeId);
    array_shift($tree);
    return $tree;
  }

  public function getChildren($nodeId) {
    $sql = "select node.* from {$this->table} as node "
        . "WHERE node.{$this->parentColumn} = :parent "
        . "ORDER BY node.{$this->leftColumn}";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array('parent' => $nodeId));

    return $this->fetch($stmt, false);
  }

  public function getTreeLeafs($treeId = null) {
    $sql = "SELECT * FROM {$this->table} WHERE {$this->quoteIdent($this->rightColumn)} = {$this->quoteIdent($this->leftColumn)} + 1";
    $params = array();
    if ($treeId != null && $this->treeColumn != null) {
      $sql .= " AND {$this->quoteIdent($this->treeColumn)} = :tree";
      $params = array(':tree' => $treeId);
    }
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $this->fetch($stmt, false);
  }

  public function getAncestors($nodeId) {

    $sql = "SELECT parent.*
            FROM {$this->quoteIdent($this->table)} AS node,{$this->quoteIdent($this->table)} AS parent
            WHERE node.{$this->quoteIdent($this->leftColumn)} BETWEEN parent.{$this->quoteIdent($this->leftColumn)} AND parent.{$this->quoteIdent($this->rightColumn)}
              AND node.{$this->quoteIdent($this->keyColumn)} = :id
              AND parent.{$this->quoteIdent($this->keyColumn)} != :id
            ORDER BY node.{$this->quoteIdent($this->leftColumn)}";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array(':id' => $nodeId));
    return $this->fetch($stmt, false);
  }

  public function getTree($parentId) {

    // ,(COUNT(parent.{$this->keyColumn}) - 1) AS depth
    $sql = "SELECT node.*
				FROM {$this->quoteIdent($this->table)} AS node,
        			{$this->quoteIdent($this->table)} AS parent
                                WHERE node.{$this->quoteIdent($this->leftColumn)} BETWEEN parent.{$this->quoteIdent($this->leftColumn)} AND parent.{$this->quoteIdent($this->rightColumn)}
        			AND parent.{$this->quoteIdent($this->keyColumn)} = :parent
				ORDER BY node.{$this->quoteIdent($this->leftColumn)};";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array('parent' => $parentId));
    return $this->fetch($stmt, false);
  }

  public function getRoots() {
    $stmt = $this->pdo->prepare('select * from ' . $this->quoteIdent($this->table) . ' where ' . $this->quoteIdent($this->parentColumn) . ' is null or ' . $this->quoteIdent($this->parentColumn) . ' = 0');
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
    $stmt = $this->pdo->prepare('select * from ' . $this->quoteIdent($this->table) . ' where ' . $this->quoteIdent($this->keyColumn) . ' = :id');
    $stmt->execute(array(':id' => $nodeId));
    return $this->fetch($stmt);
  }

  public function createRoot($data, $treeId = null) {

    $data[$this->leftColumn] = 1;
    $data[$this->rightColumn] = 2;
    $data[$this->parentColumn] = 0;

    if (isset($this->treeColumn) && $treeId == null) {
      $sql = "select max({$this->quoteIdent($this->treeColumn)}) from {$this->quoteIdent($this->table)}";
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

      $sql = "select @myLeft := {$this->quoteIdent($this->leftColumn)} FROM {$this->quoteIdent($this->table)} WHERE {$this->quoteIdent($this->keyColumn)} = :parent";
      $params = array('parent' => $parentId);
      if ($treeId) {
        $sql .= " AND {$this->quoteIdent($this->treeColumn)} = :tree";
        $params['tree'] = $treeId;
        $data[$this->treeColumn] = $treeId;
      }
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      $leftValue = $stmt->fetchColumn();

      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->rightColumn)} = {$this->quoteIdent($this->rightColumn)} + 2 WHERE {$this->quoteIdent($this->rightColumn)} > @myLeft" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->leftColumn)} = {$this->quoteIdent($this->leftColumn)} + 2 WHERE {$this->quoteIdent($this->leftColumn)} > @myLeft" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));

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
    
    $names = array_keys($data);
    $sql = 'INSERT INTO ' . $this->quoteIdent($this->table) . ' (' . implode(',', array_map(array($this, 'quoteIdent'),$names)) . ') VALUES (' .
        implode(',', array_map(function ($column) {
              return ':' . $column;
            }, $names)) . ')';

    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute($data);
  }

  public function countChildren($nodeId) {
    $sql = "select count(*) from {$this->quoteIdent($this->table)} where {$this->quoteIdent($this->parentColumn)} = :id";
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

      $sql = "select @myRight := {$this->quoteIdent($this->rightColumn)}, {$this->quoteIdent($this->parentColumn)} FROM {$this->quoteIdent($this->table)} WHERE {$this->quoteIdent($this->keyColumn)} = :sibling";
      $params = array('sibling' => $siblingId);
      if ($treeId) {
        $sql .= " AND {$this->quoteIdent($this->treeColumn)} = :tree";
        $params['tree'] = $treeId;
        $data[$this->treeColumn] = $treeId;
      }
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      list($rightValue, $parentId)= $stmt->fetch(\PDO::FETCH_NUM);
      
      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->rightColumn)} = {$this->quoteIdent($this->rightColumn)} + 2 WHERE {$this->quoteIdent($this->rightColumn)} > @myRight" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->leftColumn)} = {$this->quoteIdent($this->leftColumn)} + 2 WHERE {$this->quoteIdent($this->leftColumn)} > @myRight" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));

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
        $sql .= $this->quoteIdent($this->treeColumn) . ',';
      }

      $sql .= " @myLeft := {$this->quoteIdent($this->leftColumn)}, @myRight := {$this->quoteIdent($this->rightColumn)}, @myWidth := {$this->quoteIdent($this->rightColumn)} - {$this->quoteIdent($this->leftColumn)} + 1, @myParent := {$this->quoteIdent($this->parentColumn)} FROM {$this->quoteIdent($this->table)} WHERE {$this->quoteIdent($this->keyColumn)} = :node";
      $params = array('node' => $nodeId);
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      if ($this->treeColumn) {
        $treeId = $stmt->fetchColumn();
      }

      $sqlDelete = "DELETE FROM {$this->quoteIdent($this->table)} WHERE {$this->quoteIdent($this->leftColumn)} = @myLeft";
      if ($this->treeColumn) {
        $sqlDelete .= " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId;
      }
      $this->pdo->exec($sqlDelete);

      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->rightColumn)} = {$this->quoteIdent($this->rightColumn)} - 1, {$this->quoteIdent($this->leftColumn)} = {$this->quoteIdent($this->leftColumn)} - 1, {$this->quoteIdent($this->parentColumn)} = @myParent WHERE {$this->quoteIdent($this->leftColumn)} BETWEEN @myLeft AND @myRight" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->rightColumn)} = {$this->quoteIdent($this->rightColumn)} - 2 WHERE {$this->quoteIdent($this->rightColumn)} > @myRight" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->leftColumn)} = {$this->quoteIdent($this->leftColumn)} - 2 WHERE {$this->quoteIdent($this->leftColumn)} > @myRight" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));

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
        $sql .= $this->quoteIdent($this->treeColumn) . ',';
      }

      $sql .= " @myLeft := {$this->quoteIdent($this->leftColumn)}, @myRight := {$this->quoteIdent($this->rightColumn)}, @myWidth := {$this->quoteIdent($this->rightColumn)} - {$this->quoteIdent($this->leftColumn)} + 1 FROM {$this->quoteIdent($this->table)} WHERE {$this->quoteIdent($this->keyColumn)} = :node";
      $params = array('node' => $nodeId);
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      if ($this->treeColumn) {
        $treeId = $stmt->fetchColumn();
      }

      $sqlDelete = "DELETE FROM {$this->quoteIdent($this->table)} WHERE {$this->quoteIdent($this->leftColumn)} BETWEEN @myLeft AND @myRight";
      if ($treeId) {
        $sqlDelete .= " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId;
      }
      $this->pdo->exec($sqlDelete);

      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->rightColumn)} = {$this->quoteIdent($this->rightColumn)} - @myWidth WHERE {$this->quoteIdent($this->rightColumn)} > @myRight" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));
      $this->pdo->exec("UPDATE {$this->quoteIdent($this->table)} SET {$this->quoteIdent($this->leftColumn)} = {$this->quoteIdent($this->leftColumn)} - @myWidth WHERE {$this->quoteIdent($this->leftColumn)} > @myRight" . (isset($treeId) ? " AND {$this->quoteIdent($this->treeColumn)} = " . $treeId : ''));

      $this->pdo->exec("UNLOCK TABLES;");
      $this->pdo->commit();
    } catch (\PDOException $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  public function deleteTree($treeId) {
    $sql = "DELETE FROM {$this->quoteIdent($this->table)} WHERE {$this->quoteIdent($this->treeColumn)} = :tree";
    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute(array(':tree' => $treeId));
  }

}

class NestedSetUtils {

  public static function hierarchize($collection) {
    // TODO:
  }

  public static function treeze(array &$elements, $parentId = 0, $childrenKey = 'children') {
    $branch = array();

    foreach ($elements as $element) {
        if ($element['parent_id'] == $parentId) {
            $children = self::treeze($elements, $element['id']);
            if ($children) {
                $element[$childrenKey] = $children;
            }
            $branch[$element['id']] = $element;
            unset($elements[$element['id']]);
        }
    }
    return $branch;
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
