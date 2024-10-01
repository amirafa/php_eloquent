<?php

class Model
{
    protected $pdo;
    protected $table;
    protected $primaryKey = 'id';
    protected $attributes = [];
    protected $softDelete = false;
    protected $timestamps = true;
    protected $joins = [];
    protected $where = [];
    protected $limit;
    protected $offset;

    public function __construct()
    {
        // Establish a PDO connection (replace with your actual DB credentials)
        $this->pdo = new PDO('mysql:host=localhost;dbname=test_db', 'root', '');
    }

    // Set a table for the model
    public function getTable()
    {
        if (isset($this->table)) {
            return $this->table;
        }
        return strtolower(static::class) . 's'; // Default: 'User' -> 'users'
    }

    // Find a record by primary key
    public static function find($id)
    {
        $instance = new static();
        $sql = sprintf("SELECT * FROM %s WHERE %s = ? AND deleted_at IS NULL", $instance->getTable(), $instance->primaryKey);
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $instance->attributes = $result;
            return $instance;
        }
        return null;
    }

    // Get all records excluding soft-deleted ones
    public static function all()
    {
        $instance = new static();
        return $instance->get();
    }

    // Save (insert or update) a record
    public function save()
    {
        if ($this->timestamps) {
            $this->attributes['updated_at'] = date('Y-m-d H:i:s');
            if (!isset($this->attributes[$this->primaryKey])) {
                $this->attributes['created_at'] = date('Y-m-d H:i:s');
            }
        }

        if (isset($this->attributes[$this->primaryKey])) {
            return $this->update();
        } else {
            return $this->create();
        }
    }

    // Create a new record
    protected function create()
    {
        $columns = array_keys($this->attributes);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->getTable(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($this->attributes);
    }

    // Update an existing record
    protected function update()
    {
        $columns = array_keys($this->attributes);
        $setClause = implode(', ', array_map(fn($col) => "$col = :$col", $columns));
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s = :%s",
            $this->getTable(),
            $setClause,
            $this->primaryKey,
            $this->primaryKey
        );
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($this->attributes);
    }

    // Soft delete a record
    public function delete()
    {
        if ($this->softDelete) {
            $this->attributes['deleted_at'] = date('Y-m-d H:i:s');
            return $this->save();
        } else {
            return $this->hardDelete();
        }
    }

    // Permanently delete a record
    protected function hardDelete()
    {
        $sql = sprintf("DELETE FROM %s WHERE %s = ?", $this->getTable(), $this->primaryKey);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$this->attributes[$this->primaryKey]]);
    }

    // Magic method for setting attributes
    public function __set($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    // Magic method for getting attributes
    public function __get($key)
    {
        return $this->attributes[$key] ?? null;
    }

    // hasOne relationship (one-to-one)
    public function hasOne($relatedClass, $foreignKey, $localKey = 'id')
    {
        $relatedInstance = new $relatedClass;
        $sql = sprintf("SELECT * FROM %s WHERE %s = ?", $relatedInstance->getTable(), $foreignKey);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->{$localKey}]);
        return $stmt->fetchObject($relatedClass);
    }

    // hasMany relationship (one-to-many)
    public function hasMany($relatedClass, $foreignKey, $localKey = 'id')
    {
        $relatedInstance = new $relatedClass;
        $sql = sprintf("SELECT * FROM %s WHERE %s = ?", $relatedInstance->getTable(), $foreignKey);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->{$localKey}]);
        return $stmt->fetchAll(PDO::FETCH_CLASS, $relatedClass);
    }

    // belongsTo relationship (many-to-one)
    public function belongsTo($relatedClass, $foreignKey, $ownerKey = 'id')
    {
        $relatedInstance = new $relatedClass;
        $sql = sprintf("SELECT * FROM %s WHERE %s = ?", $relatedInstance->getTable(), $ownerKey);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->{$foreignKey}]);
        return $stmt->fetchObject($relatedClass);
    }

    // Add a JOIN to the query (INNER, LEFT, RIGHT)
    public function join($table, $firstColumn, $operator, $secondColumn, $type = 'INNER')
    {
        $this->joins[] = sprintf(" %s JOIN %s ON %s %s %s", strtoupper($type), $table, $firstColumn, $operator, $secondColumn);
        return $this;
    }

    // Add where conditions
    public function where($column, $operator, $value)
    {
        $this->where[] = sprintf("%s %s '%s'", $column, $operator, $value);
        return $this;
    }

    // Apply LIMIT
    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    // Apply OFFSET
    public function offset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    // Get the query result
    public function get()
    {
        $sql = sprintf("SELECT * FROM %s", $this->getTable());

        if (!empty($this->joins)) {
            $sql .= implode(' ', $this->joins);
        }

        if (!empty($this->where)) {
            $sql .= " WHERE " . implode(' AND ', $this->where);
        }

        if ($this->limit) {
            $sql .= " LIMIT " . $this->limit;
        }

        if ($this->offset) {
            $sql .= " OFFSET " . $this->offset;
        }

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_CLASS, static::class);
    }
}

// Example Usage:

class User extends Model
{
    protected $table = 'users'; // Optional: define table if different from class name
    protected $softDelete = true; // Enable soft deletes
}

class Profile extends Model
{
    protected $table = 'profiles';
}

// Example: Inner Join with Users and Profiles
$users = (new User())
    ->join('profiles', 'users.id', '=', 'profiles.user_id')
    ->where('users.status', '=', 'active')
    ->limit(10)
    ->get();

foreach ($users as $user) {
    echo $user->name . ' - ' . $user->bio . '<br>';
}
