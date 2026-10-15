import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../theme/colors';
import { useAuth } from '../context/AuthContext';

interface HeaderProps {
  title?: string;
  onNotificationPress?: () => void;
  onProfilePress?: () => void;
  unreadCount?: number;
}

export const Header: React.FC<HeaderProps> = ({
  title,
  onNotificationPress,
  onProfilePress,
  unreadCount = 0,
}) => {
  const { user, activeRole, setActiveRole } = useAuth();

  return (
    <View style={styles.container}>
      {/* Left: Brand Identity Matching Website */}
      <View style={styles.left}>
        <View style={styles.logoBadge}>
          <Ionicons name="hammer" size={17} color="#ffffff" style={styles.gavelIcon} />
        </View>

        <View style={styles.brandTextCol}>
          <Text style={styles.brandTitle}>
            Nexus<Text style={styles.brandHighlight}>Bids</Text>
          </Text>
          {title ? (
            <Text style={styles.subtitle} numberOfLines={1}>{title}</Text>
          ) : (
            <View style={styles.countryRow}>
              <View style={styles.livePulseDot} />
              <Text style={styles.countryBadge}>PAKISTAN</Text>
            </View>
          )}
        </View>
      </View>

      {/* Right: Role Switcher & Quick Actions */}
      <View style={styles.right}>
        {user?.role === 'seller' && (
          <TouchableOpacity
            style={[
              styles.roleSwitch,
              activeRole === 'seller' ? styles.roleSeller : styles.roleBuyer,
            ]}
            onPress={() => setActiveRole(activeRole === 'seller' ? 'buyer' : 'seller')}
            activeOpacity={0.8}
          >
            <Ionicons
              name={activeRole === 'seller' ? 'briefcase-outline' : 'cart-outline'}
              size={13}
              color={activeRole === 'seller' ? Colors.primaryLight : Colors.success}
              style={{ marginRight: 4 }}
            />
            <Text
              style={[
                styles.roleText,
                activeRole === 'seller' ? styles.roleTextSeller : styles.roleTextBuyer,
              ]}
            >
              {activeRole === 'seller' ? 'Seller Studio' : 'Buyer Mode'}
            </Text>
          </TouchableOpacity>
        )}

        {/* Notifications Icon Button */}
        <TouchableOpacity
          style={styles.iconBtn}
          onPress={onNotificationPress}
          activeOpacity={0.7}
          accessibilityLabel="Notifications"
        >
          <Ionicons
            name={unreadCount > 0 ? 'notifications' : 'notifications-outline'}
            size={20}
            color={unreadCount > 0 ? Colors.text : Colors.textMuted}
          />
          {unreadCount > 0 && (
            <View style={styles.notifBadge}>
              <Text style={styles.notifBadgeText}>
                {unreadCount > 9 ? '9+' : unreadCount}
              </Text>
            </View>
          )}
        </TouchableOpacity>

        {/* User Profile Avatar Button */}
        <TouchableOpacity
          style={styles.avatarBtn}
          onPress={onProfilePress}
          activeOpacity={0.8}
          accessibilityLabel="User profile"
        >
          {user ? (
            <Text style={styles.avatarInitials}>
              {user.full_name?.charAt(0).toUpperCase() || 'U'}
            </Text>
          ) : (
            <Ionicons name="person-outline" size={16} color={Colors.textMuted} />
          )}
        </TouchableOpacity>
      </View>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingVertical: 10,
    backgroundColor: Colors.surface,
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
  },
  left: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  logoBadge: {
    width: 36,
    height: 36,
    borderRadius: 10,
    backgroundColor: Colors.primaryDark,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.35,
    shadowRadius: 8,
    elevation: 4,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
  },
  gavelIcon: {
    transform: [{ rotate: '-15deg' }],
  },
  brandTextCol: {
    justifyContent: 'center',
  },
  brandTitle: {
    fontSize: 18,
    fontWeight: '900',
    color: Colors.text,
    letterSpacing: -0.3,
    lineHeight: 20,
  },
  brandHighlight: {
    color: Colors.primary,
  },
  subtitle: {
    fontSize: 11,
    color: Colors.textMuted,
    fontWeight: '600',
    marginTop: 1,
  },
  countryRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    marginTop: 2,
  },
  livePulseDot: {
    width: 5,
    height: 5,
    borderRadius: 2.5,
    backgroundColor: Colors.success,
  },
  countryBadge: {
    fontSize: 9,
    fontWeight: '800',
    letterSpacing: 1.1,
    color: Colors.textDim,
  },
  right: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  roleSwitch: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 9,
    paddingVertical: 5,
    borderRadius: 20,
  },
  roleSeller: {
    backgroundColor: 'rgba(99, 102, 241, 0.16)',
    borderWidth: 1,
    borderColor: 'rgba(99, 102, 241, 0.35)',
  },
  roleBuyer: {
    backgroundColor: 'rgba(16, 185, 129, 0.14)',
    borderWidth: 1,
    borderColor: 'rgba(16, 185, 129, 0.35)',
  },
  roleText: {
    fontSize: 11,
    fontWeight: '700',
  },
  roleTextSeller: {
    color: Colors.primaryLight,
  },
  roleTextBuyer: {
    color: Colors.success,
  },
  iconBtn: {
    position: 'relative',
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255, 255, 255, 0.04)',
    borderWidth: 1,
    borderColor: Colors.borderSubtle,
  },
  notifBadge: {
    position: 'absolute',
    top: 2,
    right: 2,
    minWidth: 16,
    height: 16,
    borderRadius: 8,
    backgroundColor: Colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 3,
    borderWidth: 1.5,
    borderColor: Colors.surface,
  },
  notifBadgeText: {
    color: '#ffffff',
    fontSize: 9,
    fontWeight: '900',
    lineHeight: 11,
  },
  avatarBtn: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: Colors.surfaceLight,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1.5,
    borderColor: Colors.border,
  },
  avatarInitials: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.text,
  },
});
