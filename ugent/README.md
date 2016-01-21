Build UGent ABS CollectiveAccess README
---------------------------------------

Check the Current Build Status on https://build.ugent.be/browse/ABS-CA

This build uses an ant script to package the CollectiveAccess code into a
debian package for UGent, which can be stored in a UGent debian repo.

The application can later be deployed on a application server by installing
this debian package from the UGent debian repo.


Usage:
------
- Adapt the build.properties, especially the minorVersion should be incremented
if you want to install a new version.
- Build the package with "ant fpm:package"
- The .deb package can be found in ugent/dist and is ready to be uploaded to
the UGent debian repo

----Useful Links:----

   Web site: https://wiki.ugent.be/display/ABS

   Bug Tracker: https://jira.ugent.be/browse/ABS
