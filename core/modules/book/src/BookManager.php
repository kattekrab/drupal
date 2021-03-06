<?php

/**
 * @file
 * Contains \Drupal\book\BookManager.
 */

namespace Drupal\book;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Defines a book manager.
 */
class BookManager implements BookManagerInterface {
  use StringTranslationTrait;

  /**
   * Defines the maximum supported depth of the book tree.
   */
  const BOOK_MAX_DEPTH = 9;

  /**
   * Database Service Object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Entity manager Service Object.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Books Array.
   *
   * @var array
   */
  protected $books;

  /**
   * Stores flattened book trees.
   *
   * @var array
   */
  protected $bookTreeFlattened;

  /**
   * Constructs a BookManager object.
   */
  public function __construct(Connection $connection, EntityManagerInterface $entity_manager, TranslationInterface $translation, ConfigFactoryInterface $config_factory) {
    $this->connection = $connection;
    $this->entityManager = $entity_manager;
    $this->stringTranslation = $translation;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllBooks() {
    if (!isset($this->books)) {
      $this->loadBooks();
    }
    return $this->books;
  }

  /**
   * Loads Books Array.
   */
  protected function loadBooks() {
    $this->books = array();
    $nids = $this->connection->query("SELECT DISTINCT(bid) FROM {book}")->fetchCol();

    if ($nids) {
      $query = $this->connection->select('book', 'b', array('fetch' => \PDO::FETCH_ASSOC));
      $query->fields('b');
      $query->condition('b.nid', $nids);
      $query->addTag('node_access');
      $query->addMetaData('base_table', 'book');
      $book_links = $query->execute();

      $nodes = $this->entityManager->getStorage('node')->loadMultiple($nids);
      // @todo: Sort by weight and translated title.

      // @todo: use route name for links, not system path.
      foreach ($book_links as $link) {
        $nid = $link['nid'];
        if (isset($nodes[$nid]) && $nodes[$nid]->status) {
          $link['link_path'] = 'node/' . $nid;
          $link['title'] = $nodes[$nid]->label();
          $link['type'] = $nodes[$nid]->bundle();
          $this->books[$link['bid']] = $link;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getLinkDefaults($nid) {
    return array(
      'original_bid' => 0,
      'nid' => $nid,
      'bid' => 0,
      'pid' => 0,
      'has_children' => 0,
      'weight' => 0,
      'options' => array(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getParentDepthLimit(array $book_link) {
    return static::BOOK_MAX_DEPTH - 1 - (($book_link['bid'] && $book_link['has_children']) ? $this->findChildrenRelativeDepth($book_link) : 0);
  }

  /**
   * Determine the relative depth of the children of a given book link.
   *
   * @param array
   *   The book link.
   *
   * @return int
   *   The difference between the max depth in the book tree and the depth of
   *   the passed book link.
   */
  protected function findChildrenRelativeDepth(array $book_link) {
    $query = $this->connection->select('book');
    $query->addField('book', 'depth');
    $query->condition('bid', $book_link['bid']);
    $query->orderBy('depth', 'DESC');
    $query->range(0, 1);

    $i = 1;
    $p = 'p1';
    while ($i <= static::BOOK_MAX_DEPTH && $book_link[$p]) {
      $query->condition($p, $book_link[$p]);
      $p = 'p' . ++$i;
    }

    $max_depth = $query->execute()->fetchField();

    return ($max_depth > $book_link['depth']) ? $max_depth - $book_link['depth'] : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function addFormElements(array $form, array &$form_state, NodeInterface $node, AccountInterface $account, $collapsed = TRUE) {
    // If the form is being processed during the Ajax callback of our book bid
    // dropdown, then $form_state will hold the value that was selected.
    if (isset($form_state['values']['book'])) {
      $node->book = $form_state['values']['book'];
    }
    $form['book'] = array(
      '#type' => 'details',
      '#title' => $this->t('Book outline'),
      '#weight' => 10,
      '#open' => !$collapsed,
      '#group' => 'advanced',
      '#attributes' => array(
        'class' => array('book-outline-form'),
      ),
      '#attached' => array(
        'library' => array('book/drupal.book'),
      ),
      '#tree' => TRUE,
    );
    foreach (array('nid', 'has_children', 'original_bid', 'parent_depth_limit') as $key) {
      $form['book'][$key] = array(
        '#type' => 'value',
        '#value' => $node->book[$key],
      );
    }

    $form['book']['pid'] = $this->addParentSelectFormElements($node->book);

    // @see \Drupal\book\Form\BookAdminEditForm::bookAdminTableTree(). The
    // weight may be larger than 15.
    $form['book']['weight'] = array(
      '#type' => 'weight',
      '#title' => $this->t('Weight'),
      '#default_value' => $node->book['weight'],
      '#delta' => max(15, abs($node->book['weight'])),
      '#weight' => 5,
      '#description' => $this->t('Pages at a given level are ordered first by weight and then by title.'),
    );
    $options = array();
    $nid = !$node->isNew() ? $node->id() : 'new';
    if ($node->id() && ($nid == $node->book['original_bid']) && ($node->book['parent_depth_limit'] == 0)) {
      // This is the top level node in a maximum depth book and thus cannot be moved.
      $options[$node->id()] = $node->label();
    }
    else {
      foreach ($this->getAllBooks() as $book) {
        $options[$book['nid']] = $book['title'];
      }
    }

    if ($account->hasPermission('create new books') && ($nid == 'new' || ($nid != $node->book['original_bid']))) {
      // The node can become a new book, if it is not one already.
      $options = array($nid => $this->t('- Create a new book -')) + $options;
    }
    if (!$node->book['bid']) {
      // The node is not currently in the hierarchy.
      $options = array(0 => $this->t('- None -')) + $options;
    }

    // Add a drop-down to select the destination book.
    $form['book']['bid'] = array(
      '#type' => 'select',
      '#title' => $this->t('Book'),
      '#default_value' => $node->book['bid'],
      '#options' => $options,
      '#access' => (bool) $options,
      '#description' => $this->t('Your page will be a part of the selected book.'),
      '#weight' => -5,
      '#attributes' => array('class' => array('book-title-select')),
      '#ajax' => array(
        'callback' => 'book_form_update',
        'wrapper' => 'edit-book-plid-wrapper',
        'effect' => 'fade',
        'speed' => 'fast',
      ),
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function checkNodeIsRemovable(NodeInterface $node) {
    return (!empty($node->book['bid']) && (($node->book['bid'] != $node->id()) || !$node->book['has_children']));
  }

  /**
   * {@inheritdoc}
   */
  public function updateOutline(NodeInterface $node) {
    if (empty($node->book['bid'])) {
      return FALSE;
    }

    if (!empty($node->book['bid']) && $node->book['bid'] == 'new') {
      // New nodes that are their own book.
      $node->book['bid'] = $node->id();
    }

    // Ensure we create a new book link if either the node itself is new, or the
    // bid was selected the first time, so that the original_bid is still empty.
    $new = empty($node->book['nid']) || empty($node->book['original_bid']);

    $node->book['nid'] = $node->id();

    // Create a new book from a node.
    if ($node->book['bid'] == $node->id()) {
      $node->book['pid'] = 0;
    }
    elseif ($node->book['pid'] < 0) {
      // -1 is the default value in BookManager::addParentSelectFormElements().
      // The node save should have set the bid equal to the node ID, but
      // handle it here if it did not.
      $node->book['pid'] = $node->book['bid'];
    }
    return $this->saveBookLink($node->book, $new);
  }

  /**
   * {@inheritdoc}
   */
  public function getBookParents(array $item, array $parent = array()) {
    $book = array();
    if ($item['pid'] == 0) {
      $book['p1'] = $item['nid'];
      for ($i = 2; $i <= static::BOOK_MAX_DEPTH; $i++) {
        $parent_property = "p$i";
        $book[$parent_property] = 0;
      }
      $book['depth'] = 1;
    }
    else {
      $i = 1;
      $book['depth'] = $parent['depth'] + 1;
      while ($i < $book['depth']) {
        $p = 'p' . $i++;
        $book[$p] = $parent[$p];
      }
      $p = 'p' . $i++;
      // The parent (p1 - p9) corresponding to the depth always equals the nid.
      $book[$p] = $item['nid'];
      while ($i <= static::BOOK_MAX_DEPTH) {
        $p = 'p' . $i++;
        $book[$p] = 0;
      }
    }
    return $book;
  }

  /**
   * Builds the parent selection form element for the node form or outline tab.
   *
   * This function is also called when generating a new set of options during the
   * Ajax callback, so an array is returned that can be used to replace an
   * existing form element.
   *
   * @param array $book_link
   *   A fully loaded book link that is part of the book hierarchy.
   *
   * @return array
   *   A parent selection form element.
   */
  protected function addParentSelectFormElements(array $book_link) {
    if ($this->configFactory->get('book.settings')->get('override_parent_selector')) {
      return array();
    }
    // Offer a message or a drop-down to choose a different parent page.
    $form = array(
      '#type' => 'hidden',
      '#value' => -1,
      '#prefix' => '<div id="edit-book-plid-wrapper">',
      '#suffix' => '</div>',
    );

    if ($book_link['nid'] === $book_link['bid']) {
      // This is a book - at the top level.
      if ($book_link['original_bid'] === $book_link['bid']) {
        $form['#prefix'] .= '<em>' . $this->t('This is the top-level page in this book.') . '</em>';
      }
      else {
        $form['#prefix'] .= '<em>' . $this->t('This will be the top-level page in this book.') . '</em>';
      }
    }
    elseif (!$book_link['bid']) {
      $form['#prefix'] .= '<em>' . $this->t('No book selected.') . '</em>';
    }
    else {
      $form = array(
        '#type' => 'select',
        '#title' => $this->t('Parent item'),
        '#default_value' => $book_link['pid'],
        '#description' => $this->t('The parent page in the book. The maximum depth for a book and all child pages is !maxdepth. Some pages in the selected book may not be available as parents if selecting them would exceed this limit.', array('!maxdepth' => static::BOOK_MAX_DEPTH)),
        '#options' => $this->getTableOfContents($book_link['bid'], $book_link['parent_depth_limit'], array($book_link['nid'])),
        '#attributes' => array('class' => array('book-title-select')),
        '#prefix' => '<div id="edit-book-plid-wrapper">',
        '#suffix' => '</div>',
      );
    }

    return $form;
  }

  /**
   * Recursively processes and formats book links for getTableOfContents().
   *
   * This helper function recursively modifies the table of contents array for
   * each item in the book tree, ignoring items in the exclude array or at a depth
   * greater than the limit. Truncates titles over thirty characters and appends
   * an indentation string incremented by depth.
   *
   * @param array $tree
   *   The data structure of the book's outline tree. Includes hidden links.
   * @param string $indent
   *   A string appended to each node title. Increments by '--' per depth
   *   level.
   * @param array $toc
   *   Reference to the table of contents array. This is modified in place, so the
   *   function does not have a return value.
   * @param array $exclude
   *   Optional array of Node ID values. Any link whose node ID is in this
   *   array will be excluded (along with its children).
   * @param int $depth_limit
   *   Any link deeper than this value will be excluded (along with its children).
   */
  protected function recurseTableOfContents(array $tree, $indent, array &$toc, array $exclude, $depth_limit) {
    $nids = array();
    foreach ($tree as $data) {
      if ($data['link']['depth'] > $depth_limit) {
        // Don't iterate through any links on this level.
        break;
      }
      if (!in_array($data['link']['nid'], $exclude)) {
        $nids[] = $data['link']['nid'];
      }
    }

    $nodes = $this->entityManager->getStorage('node')->loadMultiple($nids);

    foreach ($tree as $data) {
      $nid = $data['link']['nid'];
      if (in_array($nid, $exclude)) {
        continue;
      }
      $toc[$nid] = $indent . ' ' . Unicode::truncate($nodes[$nid]->label(), 30, TRUE, TRUE);
      if ($data['below']) {
        $this->recurseTableOfContents($data['below'], $indent . '--', $toc, $exclude, $depth_limit);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTableOfContents($bid, $depth_limit, array $exclude = array()) {
    $tree = $this->bookTreeAllData($bid);
    $toc = array();
    $this->recurseTableOfContents($tree, '', $toc, $exclude, $depth_limit);

    return $toc;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFromBook($nid) {
    $original = $this->loadBookLink($nid, FALSE);
    $this->connection->delete('book')
      ->condition('nid', $nid)
      ->execute();
    if ($nid == $original['bid']) {
      // Handle deletion of a top-level post.
      $result = $this->connection->query("SELECT * FROM {book} WHERE pid = :nid", array(
        ':nid' => $nid
      ))->fetchAllAssoc('nid', \PDO::FETCH_ASSOC);
      foreach ($result as $child) {
        $child['bid'] = $child['nid'];
        $this->updateOutline($child);
      }
    }
    $this->updateOriginalParent($original);
    $this->books = NULL;
    \Drupal::cache('data')->deleteTags(array('bid' => $original['bid']));
  }

  /**
   * {@inheritdoc}
   */
  public function bookTreeAllData($bid, $link = NULL, $max_depth = NULL) {
    $tree = &drupal_static(__METHOD__, array());
    $language_interface = \Drupal::languageManager()->getCurrentLanguage();

    // Use $nid as a flag for whether the data being loaded is for the whole
    // tree.
    $nid = isset($link['nid']) ? $link['nid'] : 0;
    // Generate a cache ID (cid) specific for this $bid, $link, $language, and
    // depth.
    $cid = 'book-links:' . $bid . ':all:' . $nid . ':' . $language_interface->id . ':' . (int) $max_depth;

    if (!isset($tree[$cid])) {
      // If the tree data was not in the static cache, build $tree_parameters.
      $tree_parameters = array(
        'min_depth' => 1,
        'max_depth' => $max_depth,
      );
      if ($nid) {
        $active_trail = $this->getActiveTrailIds($bid, $link);
        $tree_parameters['expanded'] = $active_trail;
        $tree_parameters['active_trail'] = $active_trail;
        $tree_parameters['active_trail'][] = $nid;
      }

      // Build the tree using the parameters; the resulting tree will be cached.
      $tree[$cid] = $this->bookTreeBuild($bid, $tree_parameters);
    }

    return $tree[$cid];
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveTrailIds($bid, $link) {
    $nid = isset($link['nid']) ? $link['nid'] : 0;
    // The tree is for a single item, so we need to match the values in its
    // p columns and 0 (the top level) with the plid values of other links.
    $active_trail = array(0);
    for ($i = 1; $i < static::BOOK_MAX_DEPTH; $i++) {
      if (!empty($link["p$i"])) {
        $active_trail[] = $link["p$i"];
      }
    }
    return $active_trail;
  }

  /**
   * {@inheritdoc}
   */
  public function bookTreeOutput(array $tree) {
    $build = array();
    $items = array();

    // Pull out just the book links we are going to render so that we
    // get an accurate count for the first/last classes.
    foreach ($tree as $data) {
      if ($data['link']['access']) {
        $items[] = $data;
      }
    }

    $num_items = count($items);
    foreach ($items as $i => $data) {
      $class = array();
      if ($i == 0) {
        $class[] = 'first';
      }
      if ($i == $num_items - 1) {
        $class[] = 'last';
      }
      // Set a class for the <li>-tag. Since $data['below'] may contain local
      // tasks, only set 'expanded' class if the link also has children within
      // the current book.
      if ($data['link']['has_children'] && $data['below']) {
        $class[] = 'expanded';
      }
      elseif ($data['link']['has_children']) {
        $class[] = 'collapsed';
      }
      else {
        $class[] = 'leaf';
      }
      // Set a class if the link is in the active trail.
      if ($data['link']['in_active_trail']) {
        $class[] = 'active-trail';
        $data['link']['localized_options']['attributes']['class'][] = 'active-trail';
      }

      // Allow book-specific theme overrides.
      $element['#theme'] = 'book_link__book_toc_' . $data['link']['bid'];
      $element['#attributes']['class'] = $class;
      $element['#title'] = $data['link']['title'];
      $node = $this->entityManager->getStorage('node')->load($data['link']['nid']);
      $element['#href'] = $node->url();
      $element['#localized_options'] = !empty($data['link']['localized_options']) ? $data['link']['localized_options'] : array();
      $element['#below'] = $data['below'] ? $this->bookTreeOutput($data['below']) : $data['below'];
      $element['#original_link'] = $data['link'];
      // Index using the link's unique nid.
      $build[$data['link']['nid']] = $element;
    }
    if ($build) {
      // Make sure drupal_render() does not re-order the links.
      $build['#sorted'] = TRUE;
      // Add the theme wrapper for outer markup.
      // Allow book-specific theme overrides.
      $build['#theme_wrappers'][] = 'book_tree__book_toc_' . $data['link']['bid'];
    }

    return $build;
  }

  /**
   * Builds a book tree, translates links, and checks access.
   *
   * @param int $bid
   *   The Book ID to find links for.
   * @param array $parameters
   *   (optional) An associative array of build parameters. Possible keys:
   *   - expanded: An array of parent link ids to return only book links that are
   *     children of one of the plids in this list. If empty, the whole outline
   *     is built, unless 'only_active_trail' is TRUE.
   *   - active_trail: An array of nids, representing the coordinates of the
   *     currently active book link.
   *   - only_active_trail: Whether to only return links that are in the active
   *     trail. This option is ignored, if 'expanded' is non-empty.
   *   - min_depth: The minimum depth of book links in the resulting tree.
   *     Defaults to 1, which is the default to build a whole tree for a book.
   *   - max_depth: The maximum depth of book links in the resulting tree.
   *   - conditions: An associative array of custom database select query
   *     condition key/value pairs; see _menu_build_tree() for the actual query.
   *
   * @return array
   *   A fully built book tree.
   */
  protected function bookTreeBuild($bid, array $parameters = array()) {
    // Build the book tree.
    $data = $this->doBookTreeBuild($bid, $parameters);
    // Check access for the current user to each item in the tree.
    $this->bookTreeCheckAccess($data['tree'], $data['node_links']);
    return $data['tree'];
  }

  /**
   * Builds a book tree.
   *
   * This function may be used build the data for a menu tree only, for example
   * to further massage the data manually before further processing happens.
   * _menu_tree_check_access() needs to be invoked afterwards.
   *
   * @see menu_build_tree()
   */
  protected function doBookTreeBuild($bid, array $parameters = array()) {
    // Static cache of already built menu trees.
    $trees = &drupal_static(__METHOD__, array());
    $language_interface = \Drupal::languageManager()->getCurrentLanguage();

    // Build the cache id; sort parents to prevent duplicate storage and remove
    // default parameter values.
    if (isset($parameters['expanded'])) {
      sort($parameters['expanded']);
    }
    $tree_cid = 'book-links:' . $bid . ':tree-data:' . $language_interface->id . ':' . hash('sha256', serialize($parameters));

    // If we do not have this tree in the static cache, check {cache_data}.
    if (!isset($trees[$tree_cid])) {
      $cache = \Drupal::cache('data')->get($tree_cid);
      if ($cache && $cache->data) {
        $trees[$tree_cid] = $cache->data;
      }
    }

    if (!isset($trees[$tree_cid])) {
      $query = $this->connection->select('book');
      $query->fields('book');
      for ($i = 1; $i <= static::BOOK_MAX_DEPTH; $i++) {
        $query->orderBy('p' . $i, 'ASC');
      }
      $query->condition('bid', $bid);
      if (!empty($parameters['expanded'])) {
        $query->condition('pid', $parameters['expanded'], 'IN');
      }
      $min_depth = (isset($parameters['min_depth']) ? $parameters['min_depth'] : 1);
      if ($min_depth != 1) {
        $query->condition('depth', $min_depth, '>=');
      }
      if (isset($parameters['max_depth'])) {
        $query->condition('depth', $parameters['max_depth'], '<=');
      }
      // Add custom query conditions, if any were passed.
      if (isset($parameters['conditions'])) {
        foreach ($parameters['conditions'] as $column => $value) {
          $query->condition($column, $value);
        }
      }

      // Build an ordered array of links using the query result object.
      $links = array();
      $result = $query->execute();
      foreach ($result as $link) {
        $link = (array) $link;
        $links[$link['nid']] = $link;
      }
      $active_trail = (isset($parameters['active_trail']) ? $parameters['active_trail'] : array());
      $data['tree'] = $this->buildBookOutlineData($links, $active_trail, $min_depth);
      $data['node_links'] = array();
      $this->bookTreeCollectNodeLinks($data['tree'], $data['node_links']);

      // Cache the data, if it is not already in the cache.
      \Drupal::cache('data')->set($tree_cid, $data, Cache::PERMANENT, array('bid' => $bid));
      $trees[$tree_cid] = $data;
    }

    return $trees[$tree_cid];
  }

  /**
   * {@inheritdoc}
   */
  public function bookTreeCollectNodeLinks(&$tree, &$node_links) {
    // All book links are nodes.
    // @todo clean this up.
    foreach ($tree as $key => $v) {
      $nid = $v['link']['nid'];
      $node_links[$nid][$tree[$key]['link']['nid']] = &$tree[$key]['link'];
      $tree[$key]['link']['access'] = FALSE;
      if ($tree[$key]['below']) {
        $this->bookTreeCollectNodeLinks($tree[$key]['below'], $node_links);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function bookTreeGetFlat(array $book_link) {
    if (!isset($this->bookTreeFlattened[$book_link['nid']])) {
      // Call $this->bookTreeAllData() to take advantage of caching.
      $tree = $this->bookTreeAllData($book_link['bid'], $book_link, $book_link['depth'] + 1);
      $this->bookTreeFlattened[$book_link['nid']] = array();
      $this->flatBookTree($tree, $this->bookTreeFlattened[$book_link['nid']]);
    }

    return $this->bookTreeFlattened[$book_link['nid']];
  }

  /**
   * Recursively converts a tree of menu links to a flat array.
   *
   * @param array $tree
   *   A tree of menu links in an array.
   * @param array $flat
   *   A flat array of the menu links from $tree, passed by reference.
   *
   * @see static::bookTreeGetFlat().
   */
  protected function flatBookTree(array $tree, array &$flat) {
    foreach ($tree as $data) {
      $flat[$data['link']['nid']] = $data['link'];
      if ($data['below']) {
        $this->flatBookTree($data['below'], $flat);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadBookLink($nid, $translate = TRUE) {
    $links = $this->loadBookLinks(array($nid), $translate);
    return isset($links[$nid]) ? $links[$nid] : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function loadBookLinks($nids, $translate = TRUE) {
    $result = $this->connection->query("SELECT * FROM {book} WHERE nid IN (:nids)", array(':nids' =>  $nids), array('fetch' => \PDO::FETCH_ASSOC));
    $links = array();
    foreach ($result as $link) {
      if ($translate) {
        $this->bookLinkTranslate($link);
      }
      $links[$link['nid']] = $link;
    }

    return $links;
  }

  /**
   * {@inheritdoc}
   */
  public function saveBookLink(array $link, $new) {
    // Keep track of Book IDs for cache clear.
    $affected_bids[$link['bid']] = $link['bid'];
    $link += $this->getLinkDefaults($link['nid']);
    if ($new) {
      // Insert new.
      $this->connection->insert('book')
        ->fields(array(
            'nid' => $link['nid'],
            'bid' => $link['bid'],
            'pid' => $link['pid'],
            'weight' => $link['weight'],
          ) + $this->getBookParents($link, (array) $this->loadBookLink($link['pid'], FALSE)))
        ->execute();
      // Update the has_children status of the parent.
      $this->updateParent($link);
    }
    else {
      $original = $this->loadBookLink($link['nid'], FALSE);
      // Using the Book ID as the key keeps this unique.
      $affected_bids[$original['bid']] = $original['bid'];
      // Handle links that are moving.
      if ($link['bid'] != $original['bid'] || $link['pid'] != $original['pid']) {
        // Update the bid for this page and all children.
        if ($link['pid'] == 0) {
          $link['depth'] = 1;
          $parent = array();
        }
        // In case the form did not specify a proper PID we use the BID as new
        // parent.
        elseif (($parent_link = $this->loadBookLink($link['pid'], FALSE)) && $parent_link['bid'] != $link['bid']) {
          $link['pid'] = $link['bid'];
          $parent = $this->loadBookLink($link['pid'], FALSE);
          $link['depth'] = $parent['depth'] + 1;
        }
        else {
          $parent = $this->loadBookLink($link['pid'], FALSE);
          $link['depth'] = $parent['depth'] + 1;
        }
        $this->setParents($link, $parent);
        $this->moveChildren($link, $original);

        // Update the has_children status of the original parent.
        $this->updateOriginalParent($original);
        // Update the has_children status of the new parent.
        $this->updateParent($link);
      }
      // Update the weight and pid.
      $query = $this->connection->update('book');
      $query->fields(array('weight' => $link['weight'], 'pid' => $link['pid'], 'bid' => $link['bid']));
      $query->condition('nid', $link['nid']);
      $query->execute();
    }
    foreach ($affected_bids as $bid) {
      \Drupal::cache('data')->deleteTags(array('bid' => $bid));
    }
    return $link;
  }

  /**
   * Moves children from the original parent to the updated link.
   *
   * @param array $link
   *   The link being saved.
   * @param array $original
   *   The original parent of $link.
   */
  protected function moveChildren(array $link, array $original) {
    $query = $this->connection->update('book');

    $query->fields(array('bid' => $link['bid']));

    $p = 'p1';
    $expressions = array();
    for ($i = 1; $i <= $link['depth']; $p = 'p' . ++$i) {
      $expressions[] = array($p, ":p_$i", array(":p_$i" => $link[$p]));
    }
    $j = $original['depth'] + 1;
    while ($i <= static::BOOK_MAX_DEPTH && $j <= static::BOOK_MAX_DEPTH) {
      $expressions[] = array('p' . $i++, 'p' . $j++, array());
    }
    while ($i <= static::BOOK_MAX_DEPTH) {
      $expressions[] = array('p' . $i++, 0, array());
    }

    $shift = $link['depth'] - $original['depth'];
    if ($shift > 0) {
      // The order of expressions must be reversed so the new values don't
      // overwrite the old ones before they can be used because "Single-table
      // UPDATE assignments are generally evaluated from left to right"
      // @see http://dev.mysql.com/doc/refman/5.0/en/update.html
      $expressions = array_reverse($expressions);
    }
    foreach ($expressions as $expression) {
      $query->expression($expression[0], $expression[1], $expression[2]);
    }

    $query->expression('depth', 'depth + :depth', array(':depth' => $shift));
    $query->condition('bid', $original['bid']);
    $p = 'p1';
    for ($i = 1; !empty($original[$p]); $p = 'p' . ++$i) {
      $query->condition($p, $original[$p]);
    }

    $query->execute();
  }

  /**
   * Sets the has_children flag of the parent of the node.
   *
   * This method is mostly called when a book link is moved/created etc. So we
   * want to update the has_children flag of the new parent book link.
   *
   * @param array $link
   *   The book link, data reflecting its new position, whose new parent we want
   *   to update.
   *
   * @return bool
   *   TRUE if the update was successful (either there is no parent to update,
   *   or the parent was updated successfully), FALSE on failure.
   */
  protected function updateParent(array $link) {
    if ($link['pid'] == 0) {
      // Nothing to update.
      return TRUE;
    }
    $query = $this->connection->update('book');
    $query->fields(array('has_children' => 1))
      ->condition('nid', $link['pid']);
    return $query->execute();
  }

  /**
   * Updates the has_children flag of the parent of the original node.
   *
   * This method is called when a book link is moved or deleted. So we want to
   * update the has_children flag of the parent node.
   *
   * @param array $original
   *   The original link whose parent we want to update.
   *
   * @return bool
   *   TRUE if the update was successful (either there was no original parent to
   *   update, or the original parent was updated successfully), FALSE on
   *   failure.
   */
  protected function updateOriginalParent(array $original) {
    if ($original['pid'] == 0) {
      // There were no parents of this link. Nothing to update.
      return TRUE;
    }
    // Check if $original had at least one child.
    $original_number_of_children = $this->connection->select('book', 'b')
      ->condition('bid', $original['bid'])
      ->condition('pid', $original['pid'])
      ->condition('nid', $original['nid'], '<>')
      ->countQuery()
      ->execute()
      ->fetchField();

    $parent_has_children = ((bool) $original_number_of_children) ? 1 : 0;
    // Update the parent. If the original link did not have children, then the
    // parent now does not have children. If the original had children, then the
    // the parent has children now (still).
    $query = $this->connection->update('book');
    $query->fields(array('has_children' => $parent_has_children))
        ->condition('nid', $original['pid']);
    return $query->execute();
  }

  /**
   * Sets the p1 through p9 properties for a book link being saved.
   *
   * @param array $link
   *   The book link to update.
   * @param array $parent
   *   The parent values to set.
   */
  protected function setParents(array &$link, array $parent) {
    $i = 1;
    while ($i < $link['depth']) {
      $p = 'p' . $i++;
      $link[$p] = $parent[$p];
    }
    $p = 'p' . $i++;
    // The parent (p1 - p9) corresponding to the depth always equals the nid.
    $link[$p] = $link['nid'];
    while ($i <= static::BOOK_MAX_DEPTH) {
      $p = 'p' . $i++;
      $link[$p] = 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function bookTreeCheckAccess(&$tree, $node_links = array()) {
    if ($node_links) {
      // @todo Extract that into its own method.
      $nids = array_keys($node_links);
      $select = $this->connection->select('node_field_data', 'n');
      $select->addField('n', 'nid');
      // @todo This should be actually filtering on the desired node status field
      //   language and just fall back to the default language.
      $select->condition('n.status', 1);

      $select->condition('n.nid', $nids, 'IN');
      $select->addTag('node_access');
      $nids = $select->execute()->fetchCol();
      foreach ($nids as $nid) {
        foreach ($node_links[$nid] as $mlid => $link) {
          $node_links[$nid][$mlid]['access'] = TRUE;
        }
      }
    }
    $this->doBookTreeCheckAccess($tree);
  }

  /**
   * Sorts the menu tree and recursively checks access for each item.
   */
  protected function doBookTreeCheckAccess(&$tree) {
    $new_tree = array();
    foreach ($tree as $key => $v) {
      $item = &$tree[$key]['link'];
      $this->bookLinkTranslate($item);
      if ($item['access']) {
        if ($tree[$key]['below']) {
          $this->doBookTreeCheckAccess($tree[$key]['below']);
        }
        // The weights are made a uniform 5 digits by adding 50000 as an offset.
        // After calling $this->bookLinkTranslate(), $item['title'] has the
        // translated title. Adding the nid to the end of the index insures that
        // it is unique.
        $new_tree[(50000 + $item['weight']) . ' ' . $item['title'] . ' ' . $item['nid']] = $tree[$key];
      }
    }
    // Sort siblings in the tree based on the weights and localized titles.
    ksort($new_tree);
    $tree = $new_tree;
  }

  /**
   * {@inheritdoc}
   */
  public function bookLinkTranslate(&$link) {
    $node = NULL;
    // Access will already be set in the tree functions.
    if (!isset($link['access'])) {
      $node = $this->entityManager->getStorage('node')->load($link['nid']);
      $link['access'] = $node && $node->access('view');
    }
    // For performance, don't localize a link the user can't access.
    if ($link['access']) {
      // @todo - load the nodes en-mass rather than individually.
      if (!$node) {
        $node = $this->entityManager->getStorage('node')
          ->load($link['nid']);
      }
      // The node label will be the value for the current user's language.
      $link['title'] = $node->label();
      $link['options'] = array();
    }
    return $link;
  }

  /**
   * Sorts and returns the built data representing a book tree.
   *
   * @param array $links
   *   A flat array of book links that are part of the book. Each array element
   *   is an associative array of information about the book link, containing the
   *   fields from the {book} table. This array must be ordered depth-first.
   * @param array $parents
   *   An array of the node ID values that are in the path from the current
   *   page to the root of the book tree.
   * @param int $depth
   *   The minimum depth to include in the returned book tree.
   *
   * @return array
   *   An array of book links in the form of a tree. Each item in the tree is an
   *   associative array containing:
   *   - link: The book link item from $links, with additional element
   *     'in_active_trail' (TRUE if the link ID was in $parents).
   *   - below: An array containing the sub-tree of this item, where each element
   *     is a tree item array with 'link' and 'below' elements. This array will be
   *     empty if the book link has no items in its sub-tree having a depth
   *     greater than or equal to $depth.
   */
  protected function buildBookOutlineData(array $links, array $parents = array(), $depth = 1) {
    // Reverse the array so we can use the more efficient array_pop() function.
    $links = array_reverse($links);
    return $this->buildBookOutlineRecursive($links, $parents, $depth);
  }

  /**
   * Builds the data representing a book tree.
   *
   * The function is a bit complex because the rendering of a link depends on
   * the next book link.
   */
  protected function buildBookOutlineRecursive(&$links, $parents, $depth) {
    $tree = array();
    while ($item = array_pop($links)) {
      // We need to determine if we're on the path to root so we can later build
      // the correct active trail.
      $item['in_active_trail'] = in_array($item['nid'], $parents);
      // Add the current link to the tree.
      $tree[$item['nid']] = array(
        'link' => $item,
        'below' => array(),
      );
      // Look ahead to the next link, but leave it on the array so it's available
      // to other recursive function calls if we return or build a sub-tree.
      $next = end($links);
      // Check whether the next link is the first in a new sub-tree.
      if ($next && $next['depth'] > $depth) {
        // Recursively call buildBookOutlineRecursive to build the sub-tree.
        $tree[$item['nid']]['below'] = $this->buildBookOutlineRecursive($links, $parents, $next['depth']);
        // Fetch next link after filling the sub-tree.
        $next = end($links);
      }
      // Determine if we should exit the loop and $request = return.
      if (!$next || $next['depth'] < $depth) {
        break;
      }
    }
    return $tree;
  }

  /**
   * {@inheritdoc}
   */
  public function bookSubtreeData($link) {
    $tree = &drupal_static(__METHOD__, array());

    // Generate a cache ID (cid) specific for this $link.
    $cid = 'book-links:subtree-cid:' . $link['nid'];

    if (!isset($tree[$cid])) {
      $tree_cid_cache = \Drupal::cache('data')->get($cid);

      if ($tree_cid_cache && $tree_cid_cache->data) {
        // If the cache entry exists, it will just be the cid for the actual data.
        // This avoids duplication of large amounts of data.
        $cache = \Drupal::cache('data')->get($tree_cid_cache->data);

        if ($cache && isset($cache->data)) {
          $data = $cache->data;
        }
      }

      // If the subtree data was not in the cache, $data will be NULL.
      if (!isset($data)) {
        $query = db_select('book', 'b', array('fetch' => \PDO::FETCH_ASSOC));
        $query->fields('b');
        $query->condition('b.bid', $link['bid']);
        for ($i = 1; $i <= static::BOOK_MAX_DEPTH && $link["p$i"]; ++$i) {
          $query->condition("p$i", $link["p$i"]);
        }
        for ($i = 1; $i <= static::BOOK_MAX_DEPTH; ++$i) {
          $query->orderBy("p$i");
        }
        $links = array();
        foreach ($query->execute() as $item) {
          $links[] = $item;
        }
        $data['tree'] = $this->buildBookOutlineData($links, array(), $link['depth']);
        $data['node_links'] = array();
        $this->bookTreeCollectNodeLinks($data['tree'], $data['node_links']);
        // Compute the real cid for book subtree data.
        $tree_cid = 'book-links:subtree-data:' . hash('sha256', serialize($data));
        // Cache the data, if it is not already in the cache.

        if (!\Drupal::cache('data')->get($tree_cid)) {
          \Drupal::cache('data')->set($tree_cid, $data, Cache::PERMANENT, array('bid' => $link['bid']));
        }
        // Cache the cid of the (shared) data using the book and item-specific cid.
        \Drupal::cache('data')->set($cid, $tree_cid, Cache::PERMANENT, array('bid' => $link['bid']));
      }
      // Check access for the current user to each item in the tree.
      $this->bookTreeCheckAccess($data['tree'], $data['node_links']);
      $tree[$cid] = $data['tree'];
    }

    return $tree[$cid];
  }

}
