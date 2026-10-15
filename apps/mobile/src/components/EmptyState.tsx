import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../theme/colors';

interface EmptyStateProps {
  icon?: string;
  iconName?: keyof typeof Ionicons.glyphMap;
  title: string;
  subtitle?: string;
  buttonText?: string;
  onButtonPress?: () => void;
}

export const EmptyState: React.FC<EmptyStateProps> = ({
  icon,
  iconName = 'cube-outline',
  title,
  subtitle,
  buttonText,
  onButtonPress,
}) => {
  // If icon was passed as an emoji or legacy string, we map common ones to Ionicons
  let resolvedIcon: keyof typeof Ionicons.glyphMap = iconName;
  if (icon) {
    if (icon.includes('🔍') || icon.includes('search')) resolvedIcon = 'search-outline';
    else if (icon.includes('❤️') || icon.includes('heart')) resolvedIcon = 'heart-outline';
    else if (icon.includes('📦') || icon.includes('box')) resolvedIcon = 'cube-outline';
    else if (icon.includes('🔨') || icon.includes('gavel')) resolvedIcon = 'hammer-outline';
    else if (icon.includes('🏆') || icon.includes('trophy')) resolvedIcon = 'trophy-outline';
    else if (icon.includes('🏷️') || icon.includes('tag')) resolvedIcon = 'pricetags-outline';
    else if (icon.includes('🚚') || icon.includes('truck')) resolvedIcon = 'car-sport-outline';
    else if (icon.includes('💰') || icon.includes('wallet')) resolvedIcon = 'wallet-outline';
    else if (icon.includes('🔔') || icon.includes('bell')) resolvedIcon = 'notifications-outline';
  }

  return (
    <View style={styles.container}>
      <View style={styles.iconCircle}>
        <Ionicons name={resolvedIcon} size={30} color={Colors.primaryLight} />
      </View>
      <Text style={styles.title}>{title}</Text>
      {subtitle && <Text style={styles.subtitle}>{subtitle}</Text>}
      {buttonText && onButtonPress && (
        <TouchableOpacity style={styles.btn} onPress={onButtonPress} activeOpacity={0.85}>
          <Text style={styles.btnText}>{buttonText}</Text>
          <Ionicons name="arrow-forward" size={14} color="#ffffff" style={{ marginLeft: 6 }} />
        </TouchableOpacity>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    alignItems: 'center',
    justifyContent: 'center',
    padding: 32,
    marginVertical: 24,
  },
  iconCircle: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: 'rgba(99, 102, 241, 0.12)',
    borderWidth: 1,
    borderColor: 'rgba(99, 102, 241, 0.25)',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 16,
  },
  title: {
    fontSize: 16,
    fontWeight: '800',
    color: Colors.text,
    textAlign: 'center',
    marginBottom: 6,
    letterSpacing: -0.2,
  },
  subtitle: {
    fontSize: 13,
    color: Colors.textMuted,
    textAlign: 'center',
    lineHeight: 19,
    marginBottom: 20,
    maxWidth: 260,
  },
  btn: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: Colors.primaryDark,
    paddingHorizontal: 18,
    paddingVertical: 10,
    borderRadius: 12,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.35,
    shadowRadius: 6,
    elevation: 3,
  },
  btnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#ffffff',
  },
});
