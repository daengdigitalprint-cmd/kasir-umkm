@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background items-center justify-center gap-3 p-6">
    <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
        <native:icon :ios="Ios::Person2" :android="Android::Group" class="text-theme-primary text-3xl" />
    </native:row>
    <native:text class="text-base font-bold text-theme-on-surface text-center">{{ $title }}</native:text>
    <native:text class="text-xs text-zinc-500 text-center">{{ $message }}</native:text>
</native:column>
