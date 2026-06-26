---
alwaysApply: true
---

当前项目为 Think-satoken Thinkphp6|8 扩展

核心依赖 think-cache [文档](https://github.com/top-think/think-cache)

当前项目 Token 无需签名，因为只是简单的 UUID 即可。

暂不处理高并发原子性问题。

项目规则

- 每次修改完代码后，都需要跑一下 test，确保代码质量
- 每次修改完代码后，都需要跑一次 analyze，确保代码符合 phpstan 规范
