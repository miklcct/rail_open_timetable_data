## [1.4.0] - 2024-04-27
* Reduce the number of database calls when loading the departure board.

## [1.3.2] - 2024-03-31
* Assume that a train always stick to the clock at departure regardless of DST
  change en-route.

## [1.3.1] - 2023-05-08
* Add Lewes - Hastings station ordering
* Don't remove the bracketed part of Maesteg (Ewenny Road) in the short name

## [1.3.0] - 2023-01-20
* Add more train categories

## [1.2.6] - 2022-12-22
* Fix permanent timetable in new MongoDB library

## [1.2.5] - 2022-12-22
* PHP 8.2 compatibility in regard to MongoDB and symfony cache

## [1.2.4] - 2022-12-20
* Improve station ordering in arrival mode

## [1.2.3] - 2022-12-19
* Fix station seeding not working properly in arrival mode

## [1.2.2] - 2022-12-19
* Preorder more stations between Clapham Junction and Epsom

## [1.2.1] - 2022-12-02
* Fix a mistake causing crash when generating timetable

## [1.2.0] - 2022-11-30
* Preorder some stations in timetables

## [1.1.1] - 2022-11-15
* Fix filter not working on arrival board

## [1.1.0] - 2022-11-15
* Add `set_generated` function to complement `get_generated`

## [1.0.0] - 2022-11-15
* initial release
