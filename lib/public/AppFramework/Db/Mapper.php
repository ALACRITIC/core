<?php
/**
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OCP\AppFramework\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IDb;


/**
 * Simple parent class for inheriting your data access layer from. This class
 * may be subject to change in the future
 * @since 7.0.0
 */
abstract class Mapper extends Access {

	protected $entityClass;

	/**
	 * @param IDBConnection $db Instance of the Db abstraction layer
	 * @param string $tableName the name of the table. set this to allow entity
	 * @param string $entityClass the name of the entity that the sql should be
	 * mapped to queries without using sql
	 * @since 7.0.0
	 */
	public function __construct(IDBConnection $db, $tableName, $entityClass=null){
		parent::__construct($db, $tableName);

		// if not given set the entity name to the class without the mapper part
		// cache it here for later use since reflection is slow
		if($entityClass === null) {
			$this->entityClass = str_replace('Mapper', '', get_class($this));
		} else {
			$this->entityClass = $entityClass;
		}
	}

	/**
	 * Deletes an entity from the table
	 * @param Entity $entity the entity that should be deleted
	 * @return Entity the deleted entity
	 * @since 7.0.0 - return value added in 8.1.0
	 */
	public function delete(Entity $entity){
		$sql = 'DELETE FROM `' . $this->getTableName() . '` WHERE `id` = ?';
		$stmt = $this->execute($sql, [$entity->getId()]);
		$stmt->closeCursor();
		return $entity;
	}

	/**
	 * Creates a new entry in the db from an entity
	 * @param Entity $entity the entity that should be created
	 * @return Entity the saved entity with the set id
	 * @since 7.0.0
	 */
	public function insert(Entity $entity){
		// get updated fields to save, fields have to be set using a setter to
		// be saved
		$properties = $entity->getUpdatedFields();
		$values = '';
		$columns = '';
		$params = [];

		// build the fields
		$i = 0;
		foreach($properties as $property => $updated) {
			$column = $entity->propertyToColumn($property);
			$getter = 'get' . ucfirst($property);

			$columns .= '`' . $column . '`';
			$values .= '?';

			// only append colon if there are more entries
			if($i < count($properties)-1){
				$columns .= ',';
				$values .= ',';
			}

			$params[] = $entity->$getter();
			$i++;

		}

		$sql = 'INSERT INTO `' . $this->getTableName() . '`(' .
				$columns . ') VALUES(' . $values . ')';

		$stmt = $this->execute($sql, $params);

		$entity->setId((int) $this->getDbConnection()->lastInsertId($this->getTableName()));

		$stmt->closeCursor();

		return $entity;
	}

	/**
	 * Updates an entry in the db from an entity
	 * @throws \InvalidArgumentException if entity has no id
	 * @param Entity $entity the entity that should be created
	 * @return Entity the saved entity with the set id
	 * @since 7.0.0 - return value was added in 8.0.0
	 */
	public function update(Entity $entity){
		// if entity wasn't changed it makes no sense to run a db query
		$properties = $entity->getUpdatedFields();
		if(count($properties) === 0) {
			return $entity;
		}

		// entity needs an id
		$id = $entity->getId();
		if($id === null){
			throw new \InvalidArgumentException(
				'Entity which should be updated has no id');
		}

		// get updated fields to save, fields have to be set using a setter to
		// be saved
		// do not update the id field
		unset($properties['id']);

		$columns = '';
		$params = [];

		// build the fields
		$i = 0;
		foreach($properties as $property => $updated) {

			$column = $entity->propertyToColumn($property);
			$getter = 'get' . ucfirst($property);

			$columns .= '`' . $column . '` = ?';

			// only append colon if there are more entries
			if($i < count($properties)-1){
				$columns .= ',';
			}

			$params[] = $entity->$getter();
			$i++;
		}

		$sql = 'UPDATE `' . $this->getTableName() . '` SET ' .
				$columns . ' WHERE `id` = ?';
		$params[] = $id;

		$stmt = $this->execute($sql, $params);
		$stmt->closeCursor();

		return $entity;
	}

	/**
	 * Creates an entity from a row. Automatically determines the entity class
	 * from the current mapper name (MyEntityMapper -> MyEntity).
	 *
	 * If row contains invalid attributes, exception BadFunctionCallException will
	 * be raised
	 *
	 * @param array $row the row which should be converted to an entity
	 * @return Entity the entity
	 * @throws \BadFunctionCallException
	 * @since 7.0.0
	 */
	public function mapRowToEntity($row) {
		unset($row['DOCTRINE_ROWNUM']); // Remove oracle workaround for limit
		return call_user_func($this->entityClass .'::fromRow', $row);
	}

	/**
	 * Returns an db result and throws exceptions when there are more or less
	 * results
	 * @see findEntity
	 * @param string $sql the sql query
	 * @param array $params the parameters of the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @throws DoesNotExistException if the item does not exist
	 * @throws MultipleObjectsReturnedException if more than one item exist
	 * @return array the result as row
	 * @since 7.0.0
	 */
	protected function findOneQuery($sql, array $params=[], $limit=null, $offset=null) {

		if ($sql instanceof IQueryBuilder) {
			$stmt = $sql->execute();
		} else {
			$stmt = $this->execute($sql, $params, $limit, $offset);
		}
		$row = $stmt->fetch();

		if($row === false || $row === null){
			$stmt->closeCursor();
			$msg = $this->buildDebugMessage(
				'Did expect one result but found none when executing', $sql, $params, $limit, $offset
			);
			throw new DoesNotExistException($msg);
		}
		$row2 = $stmt->fetch();
		$stmt->closeCursor();
		//MDB2 returns null, PDO and doctrine false when no row is available
		if( ! ($row2 === false || $row2 === null )) {
			$msg = $this->buildDebugMessage(
				'Did not expect more than one result when executing', $sql, $params, $limit, $offset
			);
			throw new MultipleObjectsReturnedException($msg);
		} else {
			return $row;
		}
	}

	/**
	 * Runs a sql query and returns an array of entities
	 * @param string $sql the prepare string
	 * @param array $params the params which should replace the ? in the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @return array all fetched entities
	 * @since 7.0.0
	 */
	protected function findEntities($sql, array $params=[], $limit=null, $offset=null) {
		$stmt = $this->execute($sql, $params, $limit, $offset);

		$entities = [];

		while($row = $stmt->fetch()){
			$entities[] = $this->mapRowToEntity($row);
		}

		$stmt->closeCursor();

		return $entities;
	}

	/**
	 * Returns an db result and throws exceptions when there are more or less
	 * results
	 * @param string $sql the sql query
	 * @param array $params the parameters of the sql query
	 * @param int $limit the maximum number of rows
	 * @param int $offset from which row we want to start
	 * @throws DoesNotExistException if the item does not exist
	 * @throws MultipleObjectsReturnedException if more than one item exist
	 * @return Entity the entity
	 * @since 7.0.0
	 */
	protected function findEntity($sql, array $params=[], $limit=null, $offset=null){
		return $this->mapRowToEntity($this->findOneQuery($sql, $params, $limit, $offset));
	}
}
