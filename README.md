Casset Package for Laravel 4
============================

Casset is an asset manager for Laravel 4 applications. Some things it can do:

* Create one or more asset containers.
* Compile less files.
* Combine assets into one file.
* Minify output.
* Accept assets from Laravel package public directories. `"package::js/file.js"`

Installation via Composer
-------------------------

Add this to you composer.json file, in the require object:

```javascript
"mmanos/casset": "1.*"
```

Add this to your composer.json file, in the repositories array:

```javascript
{
	"type": "vcs",
	"url":  "git@bitbucket.org:mmanos/laravel-casset.git"
}
```

After that, run composer install to install Casset.

Add the service provider to `app/config/app.php`, within the `providers` array.

```php
'providers' => array(
	// ...
	'Mmanos\Casset\CassetServiceProvider',
)
```

Finally, ensure the cache directory defined in the config file is created
and writable by the web server (defaults to public/assets/cache).

```console
$ mkdir public/assets/cache
$ chmod -R 777 public/assets/cache
$ touch public/assets/cache/.gitignore
```

Edit public/assets/cache/.gitignore.

```
*
!.gitignore
```

Usage
-----

Add assets to the "default" container:

```php
Casset::add('js/jquery.js');
Casset::add('less/layout.less');
```

Add assets to a custom container:

```php
Casset::container('layout')->add('js/jquery.js');
Casset::container('layout')->add('less/layout.less');
```

Render HTML tags to load assets for a container:

```php
echo Casset::container('default')->styles();
echo Casset::container('layout')->scripts();
```