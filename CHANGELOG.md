# Changelog

## [0.1.1](https://github.com/RobertBoes/patchbay/compare/v0.1.0...v0.1.1) (2026-09-29)


### Features

* bound the connection limit on the form and explain a reached limit ([fd1a5f2](https://github.com/RobertBoes/patchbay/commit/fd1a5f28524d562652d89db7e8cd1e67b6f89ee3))
* debug console, client snippets and an empty state ([684f81f](https://github.com/RobertBoes/patchbay/commit/684f81f43b93110b6f200f734161e5fc24e35dd5))
* dynamic Reverb applications ([d57ffb6](https://github.com/RobertBoes/patchbay/commit/d57ffb698af5d53611db03209967478bd49912cd))
* event log, live dashboard updates and channel occupancy ([b1164ba](https://github.com/RobertBoes/patchbay/commit/b1164ba9b4edce1f912ce9050bfdc2142449c052))
* event log, live updates and the debug console ([d666706](https://github.com/RobertBoes/patchbay/commit/d666706b24e1faff8099f7fab7f27f5c03bde2ab))
* report server health from a heartbeat inside Reverb ([57c3283](https://github.com/RobertBoes/patchbay/commit/57c3283827b9ddb20ccc0f8d4d134f985bb351fc))
* widgets on view app page ([a6e0053](https://github.com/RobertBoes/patchbay/commit/a6e00538938b0a805361e77d86e96b7d470e2c4b))


### Bug Fixes

* allowed origins written as URLs are reduced to their host. Reverb ([684f81f](https://github.com/RobertBoes/patchbay/commit/684f81f43b93110b6f200f734161e5fc24e35dd5))
* application factory didn't allow for null values on origin ([89b23e4](https://github.com/RobertBoes/patchbay/commit/89b23e4f36f3be660d358c9734e2ac9ac74b58fa))
* cap the debug console payload at Pusher's event limit ([13d6542](https://github.com/RobertBoes/patchbay/commit/13d65429417311cef6cc2252610a14bf2d5eb4e8))
* cast rate limits for Reverb and admit the panel's origin on restricted apps ([6bf948d](https://github.com/RobertBoes/patchbay/commit/6bf948df94daed43c948c453e2a50317f1585c97))
* show the server's public address, and whether it answers there ([e099534](https://github.com/RobertBoes/patchbay/commit/e099534be233b6bf39a2a908218f4ae0811e5fbc))
