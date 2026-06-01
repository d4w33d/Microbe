
## 🦠 About Microbe

**Microbe is an ultra-lightweight, single-file PHP framework designed to bring structure to scalable projects without the overhead of traditional frameworks.**

Dropped into any project root as a standalone .php file, it works equally well as a full framework or a simple function library, letting developers adopt as much or as little structure as they need.

Microbe supports both procedural and object-oriented programming styles, making it accessible to a wide range of developers and use cases. At its core, it includes an optional, integrated project scaffold builder that generates a clean, bundle-based directory tree, organizing code into self-contained, reusable bundles that keep large projects maintainable as they grow.

Whether you're prototyping a microservice, building a modular web application, or just need a few reliable utility functions, Microbe stays out of your way while quietly holding everything together.

## 📥 Installation

```bash
cd /my/project/root
curl -s https://microbe.barbichette.net/require | php
```

A file ```microbe.php``` is created.

Now, you can either let Microbe prepare the environment for you, or simply use the functions directly from any PHP file, by simply:

```php
<?php declare(strict_types=1);
require __DIR__ . DIRECTORY_SEPARATOR . 'microbe.php';
json_success([
    'some_sentence'   => create_sentence(minWords: 3, maxWords: 7),
    'foobar'          => get_nullable_str('foobar'),
    'random_password' => password(),
]);
```

### ✨ Prepare environment

If you want to let Microbe prepare some stuff for you, you can run one of these:
* ```php microbe.php core setup config```: generates a lambda configuration file.
* ```php microbe.php core setup tree```: generates the folders ready to contain your own files.
* ```php microbe.php core setup samples```: generates some controllers, styles and views to avoid the blank page.

You also have the combined action which will do the three actions above for you: ```php microbe.php core setup web```.

So, with those two lines:

```bash
curl -s https://microbe.barbichette.net/require | php
php microbe.php core setup web
```

... you have a lambda app ready-to-use. 🚀

## 📖 Documentation

The folder ```code/parts``` contains all the functions and classes exposed by Microbe.

You may want a better readable documentation.

You'll find it here: ↗️ https://microbe.barbichette.net/.
