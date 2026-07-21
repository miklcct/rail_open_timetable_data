# Library for handling rail open timetable data

This is a library which includes parsers, repositories and models for handling
rail open timetable data.

Currently only CIF format is supported, however, JSON format (from Network
Rail) parser can be added in the future.

## Migration from v1 to v2
v2 is a complete rewrite of the library, which is not backwards compatible, including the database schema.
It is not possible to use a database from the old library, a new import process is required.

### Summary of model changes
In v2, a `Schedule` is an entry in the timetable data, which has a `Period` of operation and `TimingPoint`s.
By instantiating it with a `Date`, an actual train `Service` is formed where actual real-world timestamps can be calculated.

A `Service`, at its own, represents a single portion and contains the timing points of that portion. 
The fields `$divideFrom`, `$joinTo`, `$divides`, `$joins` links this portion to other portions of the same service.

If a service divides into two portions, `$divides` will contain two elements, even though one of the portions will retain the same UID.
The calling points of `$this` will only contain the calling points before the train splits, where the remaining calls after the division will be in the `Service`s in the `$divides` array.

If a service is a result of division from a multi-portion service, `$divideFrom` will be non-null, which allows referencing back to the parent service.

The processing of associations is also simplified as well, `AssociationWithService` binds the services together with the calling index on the primary service. This library no longer do any time-based processing to find portions.

The `ServiceProperty` is also accumulated through the timing points instead of only set when it is changed.

The `$timingPoints` of a service may not start from 0, in case it is the primary portion after train division. The index is for the through service of the same UID, counted across all joining / dividing operations.

The location and station models are simplified as well, and now there are only two classes: `Location` (which represents a generic location) and `Station` (which represents a station listed in the station file, with interchange times, etc.).

### Other main changes
The caching of MongoDB departure boards has been removed. Clients are advised to implement their own caching if needed.

There is a new class, `RepositoryInterface`, which binds all the different repository together and provides a method to query / set the date for the whole database.

Support for ambiguous station names has been added. It is possible to enter a station name without the bracketed suffix. 
If there is a location with the exact match, it is returned. Otherwise, an error is thrown if it is ambiguous.