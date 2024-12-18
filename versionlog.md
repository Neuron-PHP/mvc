## 0.6.18

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
