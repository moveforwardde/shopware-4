<?php
/**
 * Shopware 4
 * Copyright © shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Components\Model;

/**
 * CategoryDenormalization-Class
 *
 * This class contains various methods to maintain
 * the denormalized representation of the Article to Category assignments.
 *
 * The assignments between artciles and categories are stored in s_articles_categories.
 * The table s_articles_categories_ro contains each assignment of s_articles_categories
 * plus additional assignments for each child category.
 *
 * Most write operations take place in s_articles_categories_ro.
 *
 * @category  Shopware
 * @package   Shopware\Components\Model
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class CategoryDenormalization
{
    /**
     * @var \PDO
     */
    protected $connection;

    /**
     * @var bool
     */
    protected $enableTransactions = true;

    /**
     * @param \PDO $connection
     */
    public function __construct(\PDO $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param  \PDO $connection
     * @return CategoryDenormalization
     */
    public function setConnection($connection)
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * @return \PDO
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @return bool
     */
    public function transactionsEnabled()
    {
        return $this->enableTransactions;
    }

    /**
     * @return CategoryDenormalization
     */
    public function enableTransactions()
    {
        $this->enableTransactions = true;
    }

    /**
     * @return CategoryDenormalization
     */
    public function disableTransactions()
    {
        $this->enableTransactions = false;
    }

    /**
     * Returns an array of all categoryIds the given $id has as parent
     *
     * Example:
     * $id = 9
     *
     * <code>
     * Array
     * (
     *     [0] => 9
     *     [1] => 5
     *     [2] => 10
     *     [3] => 3
     * )
     * <code>
     *
     * @param  integer $id
     * @return array
     */
    public function getParentCategoryIds($id)
    {
        $stmt = $this->getConnection()->prepare('SELECT id, parent FROM s_categories WHERE id = :id AND parent IS NOT NULL');
        $stmt->execute(array(':id' => $id));
        $parent = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$parent) {
            return array();
        }

        $result = array($parent['id']);

        $parent = $this->getParentCategoryIds($parent['parent']);
        if ($parent) {
            $result = array_merge($result, $parent);
        }

        $cache[$id] = $result;

        return $result;
    }

    /**
     * Returns maxcount for paging rebuildCategoryPath()
     *
     * @param  int $categoryId
     * @return int
     */
    public function rebuildCategoryPathCount($categoryId = null)
    {
        if ($categoryId === null) {
            $sql = '
                SELECT count(id)
                FROM s_categories
                WHERE parent IS NOT NULL
            ';

            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute();
        } else {
            $sql = '
                SELECT count(c.id)
                FROM  s_categories c
                WHERE c.path LIKE :categoryId
            ';

            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute(array('categoryId' => '%|' . $categoryId . '|%'));
        }

        $count = $stmt->fetchColumn();

        return (int) $count;
    }

    /**
     * Sets path for child-categories of given $categoryId
     *
     * @param  int $categoryId
     * @param  int $count
     * @param  int $offset
     * @return int
     */
    public function rebuildCategoryPath($categoryId = null, $count = null, $offset = 0)
    {
        $parameters = array();
        if ($categoryId === null) {
            $sql = '
                SELECT id, path
                FROM  s_categories
                WHERE parent IS NOT NULL
            ';
        } else {
            $sql = '
                SELECT id, path
                FROM  s_categories
                WHERE path LIKE :categoryPath
            ';

            $parameters = array(
                'categoryPath' => '%|' . $categoryId . '|%'
            );
        }

        if ($count !== null) {
            $sql = $this->limit($sql, $count, $offset);
        }

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($parameters);

        $updateStmt = $this->getConnection()->prepare('UPDATE s_categories set path = :path WHERE id = :categoryId');

        $count = 0;

        $this->beginTransaction();

        while ($category = $stmt->fetch(\PDO::FETCH_ASSOC)) {

            $parents = $this->getParentCategoryIds($category['id']);
            array_shift($parents);

            if (empty($parents)) {
                $path = null;
            } else {
                $path = implode('|', $parents);
                $path = '|' . $path . '|';
            }

            if ($category['path'] != $path) {
                $updateStmt->execute(array(':path' => $path, ':categoryId' => $category['id']));
                $count++;
            }
        }

        $this->commit();

        return $count;
    }

    /**
     * Returns maxcount for paging removeOldAssignmentsCount()
     *
     * @param  int $categoryId
     * @return int
     */
    public function removeOldAssignmentsCount($categoryId)
    {
        $sql = '
            SELECT parentCategoryId
            FROM s_articles_categories_ro
            WHERE categoryID = :categoryId
            AND parentCategoryId <> categoryID
            GROUP BY parentCategoryId
        ';

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute(array('categoryId' => $categoryId));

        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // in case that a leaf category is moved
        if (empty($rows)) {
            return 1;
        }

        return count($rows);
    }

    /**
     * Used for category movement.
     * If Category is moved to a new parentId this returns removes old connections
     *
     * @param  int $categoryId
     * @param  int $count
     * @param  int $offset
     * @return int
     */
    public function removeOldAssignments($categoryId, $count = null, $offset = 0)
    {
        $sql = '
            SELECT parentCategoryId
            FROM s_articles_categories_ro
            WHERE categoryID = :categoryId
            AND parentCategoryId <> categoryID
            GROUP BY parentCategoryId
       ';

        if ($count !== null) {
            $sql = $this->limit($sql, $count, $offset);
        }

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute(array('categoryId' => $categoryId));

        $deleteStmt = $this->getConnection()->prepare('DELETE FROM s_articles_categories_ro WHERE parentCategoryID = :categoryId AND parentCategoryId <> categoryID');

        $count = 0;

        $parentCategoryId = $stmt->fetchColumn();

        if ($parentCategoryId) {
            do {
                $deleteStmt->execute(array('categoryId' => $parentCategoryId));
                $count += $deleteStmt->rowCount();
            } while ($parentCategoryId = $stmt->fetchColumn());
        } else {
            $deleteStmt->execute(array('categoryId' => $categoryId));
            $count += $deleteStmt->rowCount();
        }

        return $count;
    }

    /**
     * Returns maxcount for paging rebuildAssignmentsCount()
     *
     * @param  int $categoryId
     * @return int
     */
    public function rebuildAssignmentsCount($categoryId)
    {
        $sql = '
            SELECT c.id
            FROM  s_categories c
            INNER JOIN s_articles_categories ac ON ac.categoryID = c.id
            WHERE c.path LIKE :categoryId
            GROUP BY c.id
        ';

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute(array('categoryId' => '%|' . $categoryId . '|%'));

        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // in case that a leaf category is moved
        if (empty($rows)) {
            return 1;
        }

        return count($rows);
    }

    /**
     * @param  int $categoryId
     * @param  int $count
     * @param  int $offset
     * @return int
     */
    public function rebuildAssignments($categoryId, $count = null, $offset = 0)
    {
        // Fetch affected categories
        $affectedCategoriesSql = '
            SELECT c.id
            FROM  s_categories c
            INNER JOIN s_articles_categories ac ON ac.categoryID = c.id
            WHERE c.path LIKE :categoryId
            GROUP BY c.id
        ';

        if ($count !== null) {
            $affectedCategoriesSql = $this->limit($affectedCategoriesSql, $count, $offset);
        }

        $stmt = $this->getConnection()->prepare($affectedCategoriesSql);
        $stmt->execute(array('categoryId' => '%|' . $categoryId . '|%'));

        $affectedCategories = array();
        while ($row = $stmt->fetchColumn()) {
            $affectedCategories[] = $row;
        }

        // in case that a leaf category is moved
        if (count($affectedCategories) === 0) {
            $affectedCategories = array($categoryId);
        }

        $assignmentsSql = 'SELECT articleID, categoryID FROM `s_articles_categories` WHERE categoryID = :categoryId';
        $assignmentsStmt = $this->getConnection()->prepare($assignmentsSql);

        $count = 0;

        $this->beginTransaction();
        foreach ($affectedCategories as $categoryId) {
            $assignmentsStmt->execute(array('categoryId' => $categoryId));

            while ($assignment = $assignmentsStmt->fetch()) {
                $count += $this->insertAssignment($assignment['articleID'], $assignment['categoryID']);
            }
        }
        $this->commit();

        return $count;
    }

    /**
     * Returns maxcount for paging rebuildAllAssignmentsCount()
     *
     * @return int
     */
    public function rebuildAllAssignmentsCount()
    {
        $sql = '
            SELECT ac.id, c.parent
            FROM  s_articles_categories ac
            INNER JOIN s_categories c
            ON ac.categoryID = c.id
            GROUP BY ac.id
        ';

        $stmt = $this->getConnection()->query($sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($rows)) {
            return 0;
        }

        return count($rows);
    }

    /**
     * @param  int $count  maximum number of assignments to denormalize
     * @param  int $offset
     * @return int number of new denormalized assignments
     */
    public function rebuildAllAssignments($count = null, $offset = 0)
    {
        $allAssignsSql = "
            SELECT ac.id, ac.articleID, ac.categoryID, c.parent
            FROM s_articles_categories ac
            INNER JOIN s_categories c ON ac.categoryID = c.id
            LEFT JOIN s_categories c2 ON c.id = c2.parent
            WHERE c2.id IS NULL
            GROUP BY ac.id
            ORDER BY articleID
        ";

        if ($count !== null) {
            $allAssignsSql = $this->limit($allAssignsSql, $count, $offset);
        }

        $assignments = $this->getConnection()->query($allAssignsSql);

        $newRows = 0;
        $this->beginTransaction();
        while ($assignment = $assignments->fetch()) {
            $newRows += $this->insertAssignment($assignment['articleID'], $assignment['categoryID']);
        }
        $this->commit();

        return $newRows;
    }

    /**
     * Inserts missing assignments in s_articles_categories_ro
     *
     * @param  int $articleId
     * @param  int $categoryId
     * @return int
     */
    private function insertAssignment($articleId, $categoryId)
    {
        $count = 0;

        $parents = $this->getParentCategoryIds($categoryId);
        if (empty($parents)) {
            return $count;
        }

        $selectSql  = '
            SELECT id
            FROM s_articles_categories_ro
            WHERE categoryID       = :categoryId
            AND   articleID        = :articleId
            AND   parentCategoryId = :parentCategoryId
        ';

        $selectStmt = $this->getConnection()->prepare($selectSql);

        $insertSql = 'INSERT INTO s_articles_categories_ro (articleID, categoryID, parentCategoryID) VALUES (:articleId, :categoryId, :parentCategoryId)';
        $insertStmt = $this->getConnection()->prepare($insertSql);

        foreach ($parents as $parentId) {
            $selectStmt->execute(array(
                ':articleId'        => $articleId,
                ':categoryId'       => $parentId,
                ':parentCategoryId' => $categoryId
            ));

            if ($selectStmt->fetchColumn() === false) {
                $count++;

                $insertStmt->execute(array(
                    ':articleId'        => $articleId,
                    ':categoryId'       => $parentId,
                    ':parentCategoryId' => $categoryId
                ));
            }
        }

        return $count;
    }

    /**
     * Removes assignments in s_articles_categories_ro
     *
     * @param  int $articleId
     * @param  int $categoryId
     * @return int
     */
    public function removeAssignment($articleId, $categoryId)
    {
        $deleteQuery = '
            DELETE FROM s_articles_categories_ro
            WHERE parentCategoryID = :categoryId
            AND articleId = :articleId
        ';

        $stmt = $this->getConnection()->prepare($deleteQuery);
        $stmt->execute(array('categoryId' => $categoryId, 'articleId' => $articleId));

        return $stmt->rowCount();
    }

    /**
     * Adds new assignment beween $articleId and $categoryId
     *
     * @param int $articleId
     * @param int $categoryId
     */
    public function addAssignment($articleId, $categoryId)
    {
        $this->beginTransaction();
        $this->insertAssignment($articleId, $categoryId);
        $this->commit();
    }

    /**
     * Removes all connections for given $articleId
     *
     * @param  int $articleId
     * @return int count of deleted rows
     */
    public function removeArticleAssignmentments($articleId)
    {
        $deleteQuery = '
            DELETE
            FROM s_articles_categories_ro
            WHERE articleID = :articleId
        ';

        $stmt = $this->getConnection()->prepare($deleteQuery);
        $stmt->execute(array('articleId' => $articleId));

        return $stmt->rowCount();
    }

    /**
     * Removes all connections for given $categoryId
     *
     * @param  int $categoryId
     * @return int count of deleted rows
     */
    public function removeCategoryAssignmentments($categoryId)
    {
        $deleteQuery = '
            DELETE ac1
            FROM s_articles_categories_ro ac0
            INNER JOIN s_articles_categories_ro ac1
                ON ac0.parentCategoryID = ac1.parentCategoryID
                AND ac0.id != ac1.id
            WHERE ac0.categoryID = :categoryId
        ';

        $stmt = $this->getConnection()->prepare($deleteQuery);
        $stmt->execute(array('categoryId' => $categoryId));

        return $stmt->rowCount();
    }

    /**
     * First try to truncate table,
     * if that Fails due to insufficient permissions, use delete query
     *
     * @return int
     */
    public function removeAllAssignments()
    {
        // TRUNCATE is faster than DELETE
        try {
            $count = $this->getConnection()->exec('TRUNCATE s_articles_categories_ro');
        } catch (\PDOException $e) {
            $count = $this->getConnection()->exec('DELETE FROM s_articles_categories_ro');
        }

        return $count;
    }

    /**
     * Removes assignments for non-existing articles or categories
     *
     * @return int
     */
    public function removeOrphanedAssignments()
    {
        $deleteOrphanedSql = '
            DELETE ac.*
            FROM s_articles_categories ac
            LEFT JOIN s_categories c ON ac.categoryID = c.id
            LEFT JOIN s_articles a ON ac.articleID = a.id
            WHERE
            c.id IS NULL
            OR a.id IS NULL
        ';

        $count = $this->getConnection()->exec($deleteOrphanedSql);

        return $count;
    }

    /**
     * Adds an adapter-specific LIMIT clause to the SELECT statement.
     *
     * @param  string     $sql
     * @param  integer    $count
     * @param  integer    $offset OPTIONAL
     * @throws \Exception
     * @return string
     */
    public function limit($sql, $count, $offset = 0)
    {
        $count = intval($count);
        if ($count <= 0) {
            throw new \Exception("LIMIT argument count=$count is not valid");
        }

        $offset = intval($offset);
        if ($offset < 0) {
            throw new \Exception("LIMIT argument offset=$offset is not valid");
        }

        $sql .= " LIMIT $count";
        if ($offset > 0) {
            $sql .= " OFFSET $offset";
        }

        return $sql;
    }

    /**
     * Wrapper around pdo::commit()
     */
    public function beginTransaction()
    {
        if ($this->transactionsEnabled()) {
            $this->getConnection()->beginTransaction();
        }
    }

    /**
     * Wrapper around pdo::commit()
     */
    public function commit()
    {
        if ($this->transactionsEnabled()) {
            $this->getConnection()->commit();
        }
    }
}
