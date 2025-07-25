#!/bin/bash

# Laravel Devtoolbox - Daily Development Health Check
# Run this script daily to check for common issues and maintain code quality

echo "🔍 Laravel Devtoolbox - Daily Health Check"
echo "=========================================="
date
echo

# Create output directory
mkdir -p storage/devtoolbox/daily

# 0. Enhanced application overview (NEW!)
echo "📊 Getting enhanced application overview..."
php artisan dev:about+ --extended --performance --format=json --output=storage/devtoolbox/daily/about.json 2>/dev/null
echo "✅ Application overview saved"
echo

# 1. Check for unused routes
echo "📍 Checking for unused routes..."
UNUSED_ROUTES=$(php artisan dev:routes:unused --format=count 2>/dev/null | jq -r '.count // 0')
if [ "$UNUSED_ROUTES" -gt 0 ]; then
    echo "⚠️  Found $UNUSED_ROUTES unused routes"
    php artisan dev:routes:unused --format=json --output=storage/devtoolbox/daily/unused-routes.json
else
    echo "✅ No unused routes found"
fi
echo

# 2. Model overview
echo "📊 Checking models..."
MODEL_COUNT=$(php artisan dev:models --format=count 2>/dev/null | jq -r '.count // 0')
echo "📄 Found $MODEL_COUNT models"

# Check for models without relationships (potential orphans)
php artisan dev:models --format=json --output=storage/devtoolbox/daily/models.json 2>/dev/null
ORPHAN_MODELS=$(jq '[.data[] | select(.relationships | length == 0)] | length' storage/devtoolbox/daily/models.json 2>/dev/null || echo "0")
if [ "$ORPHAN_MODELS" -gt 0 ]; then
    echo "⚠️  Found $ORPHAN_MODELS models without relationships"
else
    echo "✅ All models have relationships"
fi
echo

# 3. Environment consistency check
echo "⚙️ Checking environment consistency..."
if [ -f ".env.example" ]; then
    php artisan dev:env:diff --against=.env.example --format=json --output=storage/devtoolbox/daily/env-diff.json 2>/dev/null
    MISSING_VARS=$(jq '[.missing_in_env // []] | length' storage/devtoolbox/daily/env-diff.json 2>/dev/null || echo "0")
    EXTRA_VARS=$(jq '[.missing_in_compare // []] | length' storage/devtoolbox/daily/env-diff.json 2>/dev/null || echo "0")
    
    if [ "$MISSING_VARS" -gt 0 ] || [ "$EXTRA_VARS" -gt 0 ]; then
        echo "⚠️  Environment inconsistencies found:"
        echo "   - Missing in .env: $MISSING_VARS variables"
        echo "   - Extra in .env: $EXTRA_VARS variables"
    else
        echo "✅ Environment files are consistent"
    fi
else
    echo "⚠️  .env.example not found - skipping environment check"
fi
echo

# 4. Service container health (ENHANCED!)
echo "🔧 Checking service container..."
SERVICE_COUNT=$(php artisan dev:services --format=count 2>/dev/null | jq -r '.count // 0')
echo "⚙️  Found $SERVICE_COUNT registered services"

# Container bindings analysis (NEW!)
echo "🔍 Analyzing container bindings..."
php artisan dev:container:bindings --format=json --output=storage/devtoolbox/daily/container-bindings.json 2>/dev/null
BINDING_COUNT=$(jq '[.data // []] | length' storage/devtoolbox/daily/container-bindings.json 2>/dev/null || echo "0")
echo "🔗 Found $BINDING_COUNT container bindings"
echo

# 5. Provider performance check (NEW!)
echo "⚡ Checking service provider performance..."
php artisan dev:providers:timeline --slow-threshold=100 --format=json --output=storage/devtoolbox/daily/providers.json 2>/dev/null
SLOW_PROVIDERS=$(jq '[.data[] | select(.boot_time > 100)] | length' storage/devtoolbox/daily/providers.json 2>/dev/null || echo "0")
if [ "$SLOW_PROVIDERS" -gt 0 ]; then
    echo "⚠️  Found $SLOW_PROVIDERS slow service providers (>100ms)"
else
    echo "✅ All service providers boot quickly"
fi
echo

# 6. Middleware analysis
echo "🛡️ Checking middleware..."
MIDDLEWARE_COUNT=$(php artisan dev:middleware --format=count 2>/dev/null | jq -r '.count // 0')
echo "🔒 Found $MIDDLEWARE_COUNT middleware classes"
echo

# 7. Generate summary report
echo "📋 Generating summary report..."
cat > storage/devtoolbox/daily/summary.md << EOF
# Daily Health Check Summary

**Date:** $(date)

## Quick Stats
- **Models:** $MODEL_COUNT total, $ORPHAN_MODELS without relationships
- **Routes:** Found $UNUSED_ROUTES unused routes
- **Services:** $SERVICE_COUNT registered
- **Container Bindings:** $BINDING_COUNT total
- **Middleware:** $MIDDLEWARE_COUNT classes
- **Slow Providers:** $SLOW_PROVIDERS (>100ms boot time)

## Environment
- Missing variables: $MISSING_VARS
- Extra variables: $EXTRA_VARS

## Recommendations
EOF

# Add recommendations based on findings
if [ "$UNUSED_ROUTES" -gt 5 ]; then
    echo "- 🧹 Consider cleaning up unused routes (found $UNUSED_ROUTES)" >> storage/devtoolbox/daily/summary.md
fi

if [ "$ORPHAN_MODELS" -gt 0 ]; then
    echo "- 🔗 Review models without relationships - they might need cleanup" >> storage/devtoolbox/daily/summary.md
fi

if [ "$MISSING_VARS" -gt 0 ] || [ "$EXTRA_VARS" -gt 0 ]; then
    echo "- ⚙️ Synchronize environment files to avoid configuration issues" >> storage/devtoolbox/daily/summary.md
fi

if [ "$SLOW_PROVIDERS" -gt 0 ]; then
    echo "- ⚡ Optimize slow service providers for better performance" >> storage/devtoolbox/daily/summary.md
fi

echo "✅ Health check complete! Summary saved to storage/devtoolbox/daily/summary.md"
echo
echo "📁 Detailed reports available in:"
echo "   - storage/devtoolbox/daily/about.json (Enhanced app overview)"
echo "   - storage/devtoolbox/daily/models.json"
echo "   - storage/devtoolbox/daily/unused-routes.json"
echo "   - storage/devtoolbox/daily/env-diff.json"
echo "   - storage/devtoolbox/daily/container-bindings.json (NEW!)"
echo "   - storage/devtoolbox/daily/providers.json (NEW!)"
echo "   - storage/devtoolbox/daily/summary.md"