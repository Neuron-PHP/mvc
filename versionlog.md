## 0.7.1

## 0.6.49 2025-08-14
* Refactored the controller to accept an application object, not just a router so that it can give access to settings.

## 0.6.48 2025-08-13
## 0.6.47 2025-08-13
## 0.6.46 2025-08-13
* Added CLI commands.

## 0.6.45 2025-08-12
* Fixed an issue with the cache override.

## 0.6.44 2025-08-12
* Improved caching to support getting/setting cache data by a separate data key. This allows for avoiding expensive api calls in the controller when possible.

## 0.6.43 2025-08-12
* Updated blagh and mvc components.
* Added the ability to access the view cache from the controller.

## 0.6.42 2025-08-11
* Added variable injection to partials.

## 0.6.41 2025-08-11
* renamed the bootstrap functions.

## 0.6.40 2025-08-10
* Added the ability to render partials.

## 0.6.39 2025-08-10

* Added namespace to the bootstrap functions.
* Added tests.

## 0.6.38 2025-08-05

* Updated readme.

## 0.6.37 2025-08-05

* Added gc logging.

## 0.6.36 2025-08-05

* Added cache debug logging.

## 0.6.35 2025-08-04

* Added garbage collection for view file cache.

## 0.6.34 2025-08-04

* Added file based view caching.

## 0.6.33 2025-08-04

* Markdown files can now be put in nested directories.

## 0.6.32 2025-05-21
* Can now operate with no config file present and default to env settings.

## 0.6.31 2025-02-18
* Updated router.
* Cleaned up bootstrap functions.

## 0.6.30 2025-02-07
* Updated to core/application packages.
* Updated to php8.4

## 0.6.29 2025-01-27
* Updated router.

## 0.6.28
## 0.6.27
## 0.6.26
## 0.6.25
## 0.6.24 2025-01-08
* Added markdown support to the view.

## 0.6.23
* Switched the config file from ini to yaml.

## 0.6.22 2025-01-05
* Moved base path to core.

## 0.6.21
* Added the ability to have a request with no properties.

## 0.6.20 2025-01-02
Added the following request property type validation options:
* array
* boolean
* currency
* date
* date_time
* ein
* email
* float
* integer
* ip_address
* name
* numeric
* object
* string
* time
* upc
* url
* us_phone_number
* intl_phone_number

## 0.6.19 2024-12-23
* Added the boot and dispatch bootstap functions.

## 0.6.18 2024-12-17
* Code compliance updates.
* Updated the getHttpHeaders method to better handle CLI vs Server.

## 0.6.17 2024-12-17
* Fixed view path.

## 0.6.16 2024-12-17
* Added base_path to the registry.

## 0.6.15 2024-12-17
* Updated core.

## 0.6.14 2024-12-16
* Updated the core package to include event listener configuration via yaml.
* Updated the file locations.

## 0.6.13
* Removed the requirement for every route to have a request.

## 0.6.12 2024-12-15
* Implemented base_path

## 0.6.11 2024-12-15
* Fixed the root path for routes.

## 0.6.10 2024-12-15
* Added HttpResponseStatus class.

## 0.6.9 2024-12-15
* Added http response codes to the render methods.

## 0.6.8 2024-12-15
* Implemented the new routes.yml file.
* Implemented yaml based requests with validation.
* Added request required header validation.

## 0.6.7 2024-12-13
* Fixed an issue with pages using require_once to render.
* Updated the tests.

## 0.6.6 2024-12-13
* Some minor cleanup for phpmd.

## 0.6.5 2024-11-27
* Updated the routing component.

## 0.6.4 2024-11-27
* Updated composer and the core package.

## 0.6.3
* addRoute is now fluent.
* Updated the application base to support native configuration files.

## 0.6.2
* Added a title to the 404 page.

## 0.6.1 2024-11-25
* Removed legacy event code from application.

## 0.5.6 2022-04-04
* Scheduled release

## 0.5.5 2020-09-28
* Updated events component.

## 0.5.4
* Updated the default view path.

## 0.5.3
* Fixed an issue with the default controller namespace.

## 0.5.2
* Fixed default namespace to App\Controllers

## 0.5.1 
* Forced release for composer.

## 0.5.0 2020-09-09
* Added 404 event.
* Added the ability to override the namespace via parameter array.
* Updated the controller base to support dynamic registration of routes based on the presence of certain methods.
* Completed first draft of the html view.
