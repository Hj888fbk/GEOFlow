# 五渠道候选映射契约

本 Skill 只做字段映射，不加载账号、不操作浏览器、不调用 API、不点击发布。所有输出均标记 `candidate_only` 和 `published=false`。

## WordPress 官网

- 当前内容类型：`post`；`page` 与 `product` 仍阻断。
- 字段：`title、slug、excerpt、content_markdown、meta_description、faq、internal_links、media、sources、schema_nodes`。
- Skill 不调用 WordPress Bridge；GEOFlow 审核后才可走备份、应用、回读和回滚。

## 百度爱采购

- 类型：`product`，浏览器辅助草稿。
- 字段：`product_title` 最多 60 字、`selling_points` 最多 5 项、`attributes、detail_markdown、images` 最多 10 张、`source_ids、inquiry_requirements`。
- 需要运营人员最终确认。

## 1688

- 类型：`product`，浏览器辅助草稿。
- 字段：`subject` 最多 60 字、`attributes、description_markdown、images` 最多 8 张、`price=null、price_note、source_ids`。
- 不自动生成固定价格，需要运营人员最终确认。

## 搜狐号

- 类型：`article`，浏览器辅助草稿。
- 字段：`title` 最多 30 字、`summary` 最多 120 字、`content_markdown、images` 最多 9 张、`source_ids`。

## 百家号

- 类型：`article`，浏览器辅助草稿。
- 字段：`title` 最多 30 字、`abstract` 最多 120 字、`content_markdown、cover_images` 最多 3 张、`source_ids`。

## 安全停止

验证码、登录过期、账号不一致、页面结构漂移、授权失效、缺少账号绑定或字段超限都必须交还 GEOFlow 处理。Skill 不提供绕过、重试登录或 Cookie 迁移方案。
