<?php

namespace QUI\Search\Items;

use QUI;
use QUI\Exception;
use QUI\Interfaces\Projects\Site as QUISiteInterface;
use QUI\Interfaces\Users\User;

/**
 * Class CustomSearchItem
 *
 * Special custom search item for fulltext and quick search
 *
 * This is handled like a real Site in the search context
 */
class CustomSearchItem extends QUI\QDOM implements QUISiteInterface
{
    protected int $id;

    protected string $origin;

    protected string $url;

    protected QUI\Projects\Project | null $Project = null;

    /**
     * CustomFulltextItem constructor.
     *
     * @param int $id - Custom ID that can be freely chosen and that uniquely represents the search item; must be unique for your origin!
     * @param string $origin - Package this instance originates from (e.g. "quiqqer/search")
     * @param string $title - Title that is shown as a search list result item title or quicksearch suggestion
     * @param string $url - The URL that leads to your search item Site
     * @param array<string, mixed> $attributes Optional site-like attributes (e.g. "image_site", "short" etc.)
     */
    public function __construct(
        int $id,
        string $origin,
        string $title,
        string $url,
        array $attributes = []
    ) {
        $this->id = $id;
        $this->origin = $origin;
        $this->url = $url;

        $this->setAttributes($attributes);
        $this->setAttribute('title', $title);
    }

    /**
     * @return string
     */
    public function getOrigin(): string
    {
        return $this->origin;
    }

    /**
     * Return the project object of the site
     *
     * @return QUI\Projects\Project
     * @throws Exception
     */
    public function getProject(): QUI\Projects\Project
    {
        if ($this->Project instanceof QUI\Projects\Project) {
            return $this->Project;
        }

        $Project = QUI::getRewrite()->getProject();

        if (!$Project instanceof QUI\Projects\Project) {
            throw new Exception('Custom search item requires a project context.');
        }

        return $Project;
    }

    /**
     * @param QUI\Projects\Project $Project
     */
    public function setProject(QUI\Projects\Project $Project): void
    {
        $this->Project = $Project;
    }

    /**
     * Lädt die Plugins der Seite
     *
     * @param boolean|string $plugin - Plugin welches geladen werden soll, optional, ansonsten werden alle geladen
     *
     * @return QUISiteInterface
     */
    public function load(bool | string $plugin = false): QUISiteInterface
    {
        return $this;
    }

    /**
     * Serialisierungsdaten
     *
     * @return string
     */
    public function encode(): string
    {
        return '';
    }

    /**
     * Setzt JSON Parameter
     *
     * @param string $params - JSON encoded string
     */
    public function decode(string $params): void
    {
    }

    /**
     * Hohlt frisch die Daten aus der DB
     */
    public function refresh(): void
    {
    }

    /**
     * Prüft ob es eine Verknüpfung ist
     *
     * @return false|integer
     */
    public function isLinked(): false | int
    {
        return false;
    }

    /**
     * Prüft ob es die Seite auch in einer anderen Sprache gibt
     *
     * @param string $lang
     * @param boolean $check_only_active - check only active pages
     *
     * @return boolean
     */
    public function existLang(string $lang, bool $check_only_active = true): bool
    {
        return false;
    }

    /**
     * Gibt die IDs von Sprachverknüpfungen zurück
     *
     * @return array<string, int|false>
     */
    public function getLangIds(): array
    {
        return [];
    }

    /**
     * Return the ID of the site,
     * or the ID of the sibling (linked) site of another language
     *
     * @param boolean|string $lang - optional, if it is set, then the language of the wanted to be linked sibling site
     *
     * @return integer
     */
    public function getId(bool | string $lang = false): int
    {
        return $this->id;
    }

    /**
     * Gibt alle Kinder zurück
     *
     * @param array<string, mixed> $params Parameter für die Childrenausgabe
     *                      $params['where']
     *                      $params['limit']
     * @param boolean $load - Legt fest ob die Kinder die Plugins laden sollen
     *
     * @return array<int, mixed>|int
     */
    public function getChildren(array $params = [], bool $load = false): int | array
    {
        return [];
    }

    /**
     * Liefert die nächstfolgende Seite
     *
     * @return QUI\Projects\Site
     * @throws QUI\Exception
     */
    public function nextSibling(): QUISiteInterface
    {
        throw new QUI\Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.site.no.next.sibling')
        );
    }

    /**
     * Die nächsten x Kinder
     *
     * @param integer $no
     *
     * @return array<int, QUISiteInterface>
     */
    public function nextSiblings(int $no): array
    {
        return [];
    }

    /**
     * Liefert die vorhergehenden Seite
     *
     * @return QUI\Projects\Site
     * @throws QUI\Exception
     */
    public function previousSibling(): QUISiteInterface
    {
        throw new QUI\Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.site.no.previous.sibling')
        );
    }

    /**
     * Die x vorhergehenden Geschwister
     *
     * @param integer $no
     *
     * @return array<int, QUISiteInterface>
     */
    public function previousSiblings(int $no): array
    {
        return [];
    }

    /**
     * Gibt das erste Kind der Seite zurück
     *
     * @param array<string, mixed> $params
     *
     * @return QUI\Projects\Site | false
     */
    public function firstChild(array $params = []): QUISiteInterface | false
    {
        return false;
    }

    /**
     * Gibt die Kinder zurück achtet aber auf "Nicht in Navigation anzeigen" und Rechte
     *
     * @param array<string, mixed> $params
     * @return array<int, mixed>|int
     */
    public function getNavigation(array $params = []): int | array
    {
        return [];
    }

    /**
     * Gibt ein Kind zurück welches den Namen hat
     *
     * @param string $name
     *
     * @return integer
     * @throws QUI\Exception
     */
    public function getChildIdByName(string $name): int
    {
        throw new QUI\Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.site.child.by.name.not.found', [
                'name' => $name
            ]),
            705
        );
    }

    /**
     * Return a children by id
     *
     * @param integer $id
     *
     * @return QUI\Projects\Site
     * @throws QUI\Exception
     */
    public function getChild(int $id): QUISiteInterface
    {
        throw new QUI\Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.site.child.not.found'),
            705
        );
    }

    /**
     * Gibt die ID's der Kinder zurück
     * Wenn nur die ID's verwendet werden sollte dies vor getChildren verwendet werden
     *
     * @param array<string, mixed> $params Parameter für die Childrenausgabe
     *                      $params['where']
     *                      $params['limit']
     *
     * @return array<int, int>|int
     */
    public function getChildrenIds(array $params = []): int | array
    {
        return [];
    }

    /**
     * Return ALL children ids under the site
     *
     * @param array<string, mixed> $params DB parameters
     *
     * @return array<int, int>
     */
    public function getChildrenIdsRecursive(array $params = []): array
    {
        return [];
    }

    /**
     * Gibt zurück ob Site Kinder besitzt
     *
     * @param boolean $navhide - if navhide == false, navhide must be 0
     *
     * @return integer - Anzahl der Kinder
     */
    public function hasChildren(bool $navhide = false): int
    {
        return 0;
    }

    /**
     * Setzt das delete Flag
     *
     * @todo move to Site/Edit
     */
    public function delete(): bool
    {
        return false;
    }

    /**
     * Gibt die URL der Seite zurück
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $getParams
     *
     * @return string
     */
    public function getUrl(array $params = [], array $getParams = []): string
    {
        return $this->url;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $getParams
     * @return string
     */
    public function getUrlRewritten(array $params = [], array $getParams = []): string
    {
        return $this->url;
    }

    /**
     * Return the Parent id from the site object
     *
     * @return integer
     */
    public function getParentId(): int
    {
        return 1;
    }

    /**
     * Gibt alle direkten Eltern Ids zurück
     *
     * Site
     * ->Parent
     * ->Parent
     * ->Parent
     *
     * @return array<int, int>
     */
    public function getParentIds(): array
    {
        return [1];
    }

    /**
     * Return the Parent ID List
     *
     * @return array<int, int>
     */
    public function getParentIdTree(): array
    {
        return [1];
    }

    /**
     * Gibt das Parent Objekt zurück.
     * Wenn kein Parent Objekt existiert wird false zurückgegeben.
     *
     * @return QUISiteInterface|false
     * @throws Exception
     */
    public function getParent(): QUISiteInterface | false
    {
        return $this->getProject()->get(1);
    }

    /**
     * Gibt alle rekursive Parents als Objekte zurück
     * Site->Parent->ParentParent->ParentParentParent
     *
     * @return array<int, QUISiteInterface|false>
     * @throws Exception
     */
    public function getParents(): array
    {
        return [$this->getParent()];
    }

    /**
     * Stellt die Seite wieder her
     *
     * ??? wieso hier? und nicht im trash? O.o
     */
    public function restore(): void
    {
    }

    /**
     * Zerstört die Seite
     * Die Seite wird komplett aus der DB gelöscht und auch alle Beziehungen
     * Funktioniert nur wenn die Seite gelöscht ist
     */
    public function destroy(): void
    {
    }

    /**
     * Canonical URL - Um doppelte Inhalt zu vermeiden
     *
     * @return string
     */
    public function getCanonical(): string
    {
        return $this->url; // @todo
    }

    /**
     * Löscht den Seitencache
     */
    public function deleteCache(): void
    {
    }

    /**
     * Löscht den Seitencache
     */
    public function createCache(): void
    {
    }

    /**
     * Shortcut for QUI\Permissions\Permission::hasSitePermission
     *
     * @param string $permission - name of the permission
     * @param User|null $User - optional
     *
     * @return boolean|integer
     */
    public function hasPermission(string $permission, null | QUI\Interfaces\Users\User $User = null): bool | int
    {
        return true;
    }

    /**
     * Shortcut for QUI\Permissions\Permission::checkSitePermission
     *
     * @param string $permission - name of the permission
     * @param User|null $User - optional
     */
    public function checkPermission(string $permission, null | QUI\Interfaces\Users\User $User = null): void
    {
    }

    /**
     * CustomFulltextItem data as array
     *
     * @return array{
     *     id: int,
     *     origin: string,
     *     title: mixed,
     *     url: string,
     *     attributes: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin,
            'title' => $this->getAttribute('title'),
            'url' => $this->url,
            'attributes' => $this->getAttributes()
        ];
    }
}
