<?php

/**
 * Import KB Glpi
 *
 * Copyright (c) 2017.
 *
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * It is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this file. If not, see <http://www.gnu.org/licenses/>.
 *
 * ------------------------------------------------------------------------
 *
 * This file is used to import issues contained in a Json file to the Glpi
 * Knowledge base.
 *
 * ------------------------------------------------------------------------
 *
 * @package   import_kb_glpi
 * @author    Frédéric Mohier
 * @copyright Copyright (c) 2017 DCS
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 *
 */

$doc = <<<DOC
import_kb_glpi.php

Usage:
    import_kb_glpi.php [<name>] [--entity=<entity>] [--quiet]
    import_kb_glpi.php (-h | --help)
    import_kb_glpi.php (-q | --quiet)
    import_kb_glpi.php (-n | --no-update)
    import_kb_glpi.php --version

Options:
    -h --help           Show this screen.
    -q --quiet          Runs quietly (no output).
    -n --no-update      Do not update existing items (documents, categories, ...).
    --version           Show version.
    --entity=<entity>   Entity to import to [default: Root entity].

DOC;

chdir(dirname($_SERVER["SCRIPT_FILENAME"]));

include("./docopt.php");

$DIR_PREFIX="../../";
$DIR_PREFIX="/var/www/html/glpi-9.1";
include ("$DIR_PREFIX/inc/includes.php");
include ("$DIR_PREFIX/inc/entity.class.php");
include ("$DIR_PREFIX/inc/knowbaseitemcategory.class.php");
include ("$DIR_PREFIX/inc/knowbaseitem.class.php");
include ("$DIR_PREFIX/inc/documentcategory.class.php");
include ("$DIR_PREFIX/inc/document.class.php");
include ("$DIR_PREFIX/inc/document_item.class.php");

/**
 * Process arguments passed to the script
 */
$args = Docopt::handle($doc, array('version'=>'import_kb_glpi 0.1'));

$verbose = TRUE;
if ($args['--quiet']) {
    $verbose = FALSE;
}
$update = TRUE;
if ($args['--no-update']) {
    $update = FALSE;
}
$entity = 'Root entity';
if ($args['--entity']) {
    $entity = $args['--entity'];
}
$file = './import_kb_glpi.json';
if ($args['<name>']) {
    $file = $args['<name>'];
}

/**
 * Script for importing JSON formatted issues into the GLPI Knowledge base
 * - search and open the JSON file
 * - import found IP ranges and relations with SNMP authentications
 */
$file_content = file_get_contents($file);
if (! $file_content) {
    die("The file $file cannot be opened!\n");
}
$json = json_decode($file_content, true); // decode the JSON into an associative array

/* Ignore users in the file...
$logger->info("--- Users:---");
foreach ($json['users'] as $field => $user) {
    echo "Found a user:";
    foreach ($user as $key => $value) {
        if (in_array($key, array("name", "fullname", "email"))) {
            echo " - $key => $value";
        }
    }
}
*/
$db_entity = new Entity();
$db_entities = $db_entity->find("`completename`='".$entity."'", '', 1);
if (count($db_entities) > 0) {
    $found_entity = current($db_entities);
    $entity_id = $found_entity["id"];
    make_log("-> found " . count($db_entities) . " matching entity: " . $found_entity["completename"] . "\n");
} else {
    die("The entity '$entity' does not exist in the database!\n");
}

/* Projects */
make_log("--- Projects:---");
foreach ($json['projects'] as $field => $project) {
    make_log("Found a project: " . $project["name"]);
    $category_name = $project["name"];

    /*
     * Search / create a KB category for the project
     */
    $category_id = NULL;
    $db_kb_category = new KnowbaseItemCategory();
    $categories = $db_kb_category->find("`name`='".$category_name."'", '', 1);
    $input = array(
        'entities_id'   => $entity_id,
        'name'          => $category_name,
        'completename'  => $category_name,
        'comment'       => "Category created when importing the file $file"
    );

    if (count($categories) > 0) {
        $found_category = current($categories);
        $category_id = $found_category["id"];
        make_log("-> found an existing KB category for this project: " . $found_category["completename"]);
        if ($update) {
            make_log("-> updating a KB category: '$category_name'...", TRUE);
            $input["id"] = $category_id;
            $category_id = $db_kb_category->update($input);
            if (! $category_id) {
                die("Error when updating a KB category: '$category_name'!\n");
            } else {
                make_log("updated.", TRUE);
            }
        }
    } else {
        // Create a new KB category
        make_log("-> creating a new KB category: '$category_name'...", TRUE);
        $category_id = $db_kb_category->add($input);
        if (! $category_id) {
            die("Error when creating a new KB category: '$category_name'!\n");
        } else {
            make_log("created.", TRUE);
        }
    }

    /*
     * Search / create a document category for the project
     */
    $doc_category_id = NULL;
    $db_doc_category = new DocumentCategory();
    $categories = $db_doc_category->find("`name`='".$category_name."'", '', 1);
    // Create a new document category
    $input = array(
        'entities_id'   => $entity_id,
        'name'          => $category_name,
        'completename'  => $category_name,
        'comment'       => "Category created when importing the file $file"
    );

    if (count($categories) > 0) {
        $found_doc_category = current($categories);
        $doc_category_id = $found_doc_category["id"];
        make_log("-> found an existing document category for this project: " . $found_doc_category["completename"]);
        if ($update) {
            make_log("-> updating a document category: '$category_name'...", TRUE);
            $input["id"] = $doc_category_id;
            $doc_category_id = $db_doc_category->update($input);
            if (! $doc_category_id) {
                die("Error when updating a document category: '$category_name'!\n");
            } else {
                make_log("updated.", TRUE);
            }
        }
    } else {
        make_log("-> creating a new document category: '$category_name'...", TRUE);
        $doc_category_id = $db_doc_category->add($input);
        if (! $doc_category_id) {
            die("Error when creating a new document category: '$category_name'!\n");
        } else {
            make_log("created.", TRUE);
        }
    }

    foreach ($project["issues"] as $field => $issue) {
        $id = $issue["key"];
        $title = "[$id]: " . $issue["summary"];
        $description = $issue["description"];
        $reporter = $issue["reporter"];
        $type = $issue["issueType"];
        $priority = $issue["priority"];
        $creation_date = date('Y/m/d', $issue["created"] / 1000);

        $body = "<p>$title</p>";
        $body .= "<p>---</p>";
        $body .= "<p>Issue: $id, créée le: $creation_date par: $reporter</p>";
        $body .= "<p>Type: $type, priorité: $priority</p>";
        $body .= "<p>---</p>";
        $body .= "<p>$description</p>";

        /* Attachments */
        $documents = array();
        if (count($issue["attachments"]) > 0) {
            $body .= "---\n\nDocuments joints:";
            foreach ($issue["attachments"] as $attachment) {
                $date = date('Y/m/d', $attachment["created"] / 1000);
                $author = $attachment["attacher"];
                $uri = $attachment["uri"];
                $doc_name = "[$id]: " . basename($uri);

                /*
                 * Search / create a document for the attachment
                */
                $doc_id = NULL;
                $found_doc = NULL;
                $db_doc = new Document();
                $docs = $db_doc->find("`name`='".$doc_name."'", '', 1);

                if (count($docs) > 0) {
                    $found_doc = current($docs);
                    $doc_id = $found_doc["id"];
                    make_log("-> found an existing document for this attachment: " . $found_doc["name"]);

                    // Update a document
                    if ($update) {
                        /*
                         * Get the attachment content and store in a GLPI temporary file
                         */
                        make_log("-> getting the document: '$uri'...");
                        $attachment_content = file_get_contents($uri);
                        if (! $attachment_content) {
                            die("The attachment $attachment_content cannot be opened!\n");
                        }
                        $tmp_filename = GLPI_TMP_DIR . "/ikg_" . date('Y-m-d') . "_" . basename($uri);
                        make_log("-> tmp file: '$tmp_filename'...");
                        $handle = fopen($tmp_filename, "c");
                        fwrite($handle, $attachment_content);
                        fclose($handle);

                        $input = array(
                            'id'                    => $doc_id,
                            'entities_id'           => $entity_id,
                            '_filename'             => array(basename($tmp_filename)),
                            'name'                  => $doc_name,
                            'link'                  => $uri,
                            'documentcategories_id' => $doc_category_id
                        );

                        make_log("-> updating a document: '$uri'...");
                        $doc_id = $db_doc->update($input);
                        if (!$doc_id) {
                            die("Error when updating a document: '$uri'!\n");
                        } else {
                            make_log("updated.", TRUE);
                        }
                    }
                } else {
                    // Create a new document
                    make_log("-> creating a new document: '$uri'...", TRUE);

                    /*
                         * Get the attachment content and store in a GLPI temporary file
                     */
                    make_log("-> getting the document: '$uri'...");
                    $attachment_content = file_get_contents($uri);
                    if (! $attachment_content) {
                        die("The attachment $attachment_content cannot be opened!\n");
                    }
                    $tmp_filename = GLPI_TMP_DIR . "/ikg_" . date('Y-m-d') . "_" . basename($uri);
                    make_log("-> tmp file: '$tmp_filename'...");
                    $handle = fopen($tmp_filename, "c");
                    fwrite($handle, $attachment_content);
                    fclose($handle);

                    $input = array(
                        'entities_id'           => $entity_id,
                        '_filename'             => array(basename($tmp_filename)),
                        'name'                  => $doc_name,
                        'link'                  => $uri,
                        'documentcategories_id' => $doc_category_id
                    );

                    $doc_id = $db_doc->add($input);
                    if (! $doc_id) {
                        die("Error when creating a new document: '$uri'!\n");
                    } else {
                        make_log("created.", TRUE);
                    }
                }

                $db_doc = new Document();
                $docs = $db_doc->find("`name`='".$doc_name."'", '', 1);
                if (count($docs) > 0) {
                    $found_doc = current($docs);
                    $db_doc->getFromDB($found_doc['id']);
                    array_push($documents, $found_doc['id']);
                    $body .= $DB->escape("<p> . $date, $author: " . $db_doc->getDownloadLink() . "</p>");
                } else {
                    $body .= $DB->escape("<p> . $date, $author: missing attachment ($uri)!</p>");
                }
            }
        }

        /* Comments */
        if (count($issue["comments"]) > 0) {
            $body .= "---\n\nCommentaires:";
            foreach ($issue["comments"] as $comment) {
                $date = date('Y/m/d', $comment["created"] / 1000);
                $author = $comment["author"];
                $text = str_replace("\r\n", "\n", $comment["body"]);
                $body .= $DB->escape("<p> . $date, $author: $text</p>");
            }
        }

        /* History */
        if (count($issue["history"]) > 0) {
            $body .= "---\n\nHistorique:";
            foreach ($issue["history"] as $event) {
                $date = date('Y/m/d', $event["created"] / 1000);
                $author = $event["author"];
                $items = "";
                foreach ($event["items"] as $item) {
                    $field = $item["field"];
                    if (strcmp($field, "status") != 0) {
                        make_log("  ***** event: " . $date . ", " . $author . ": " . $field, TRUE);
                    } else {
                        $old = $item["oldValue"];
                        if (is_numeric($old)) {
                            $old = $item["oldDisplayValue"];
                        }
                        $new = $item["newValue"];
                        if (is_numeric($new)) {
                            $new = $item["newDisplayValue"];
                        }
                        $items .= "$field: $old -> $new";
                    }
                }
                if (strcmp($items, "") != 0) {
                    $body .= $DB->escape("<p> . $date, $author: $items</p>");
                }
            }
        }

        /*
         * Search / create a KB item for the issue
         */
        $item_id = NULL;
        $db_kb_item = new KnowbaseItem();
        $items = $db_kb_item->find("`name`='".$title."'", '', 1);
        if (count($items) > 0) {
            $found_item = current($items);
            $item_id = $found_item["id"];
            make_log("-> found an existing KB item for this issue: " . $found_item["name"]);
        } else {
            // Create a new KB item
            $input = array(
                'knowbaseitemcategories_id' => $category_id,
                'name'                      => $title,
                'answer'                    => $body
            );

            make_log("-> creating a new KB item: '$title'...", TRUE);
            $item_id = $db_kb_item->add($input);
            if (! $item_id) {
                die("Error when creating a new KB item: '$title'!\n");
            } else {
                make_log("created.", TRUE);
            }
        }

        if (count($documents) > 0) {
            $db_doc_item = new Document_Item();
            foreach ($documents as $doc_id) {
                $relations = $db_doc_item->find("`documents_id`='".$doc_id."' AND `itemtype`='KnowbaseItem' AND `items_id`='".$item_id."'", '', 1);
                if (count($relations) <= 0) {
                    // Create a new document / item relation
                    make_log("-> creating a new item / document relation...");

                    $input = array(
                        'entities_id'           => $entity_id,
                        'documents_id'          => $doc_id,
                        'items_id'              => $item_id,
                        'itemtype'              => "KnowbaseItem"
                    );

                    $doc_id = $db_doc_item->add($input);
                    if (! $doc_id) {
                        die("Error when creating a new item / document relation: '$uri'!\n");
                    } else {
                        make_log("created.", TRUE);
                    }
                }
            }
        }
        make_log(" - issue: \n$body");
//        exit(1);
    }
}

/*
foreach ($json as $key => $value) {
}


foreach ($json['projects'] as $field => $value) {
    // Use $field and $value here
}
*/

function make_log($msg="", $always=FALSE)
{
    global $verbose;

    if ($verbose || $always) {
        print($msg . PHP_EOL);
    }
}
?>
