import React, { useState, useEffect } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Animated } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../theme/colors';
import { NotificationService, PushNotificationPayload } from '../services/notificationService';

interface Props {
  onPressNotification?: (notif: PushNotificationPayload) => void;
}

export const PushNotificationBanner: React.FC<Props> = ({ onPressNotification }) => {
  const [currentNotif, setCurrentNotif] = useState<PushNotificationPayload | null>(null);
  const [slideAnim] = useState(new Animated.Value(-120));

  useEffect(() => {
    const unsubscribe = NotificationService.subscribe((notif) => {
      setCurrentNotif(notif);
      Animated.spring(slideAnim, {
        toValue: 0,
        useNativeDriver: true,
        friction: 6,
      }).start();

      const timer = setTimeout(() => {
        dismiss();
      }, 4500);

      return () => clearTimeout(timer);
    });

    return unsubscribe;
  }, []);

  const dismiss = () => {
    Animated.timing(slideAnim, {
      toValue: -140,
      duration: 250,
      useNativeDriver: true,
    }).start(() => {
      setCurrentNotif(null);
    });
  };

  if (!currentNotif) return null;

  const getNotifDetails = (): {
    borderColor: string;
    icon: keyof typeof Ionicons.glyphMap;
    iconColor: string;
    bgColor: string;
  } => {
    switch (currentNotif.type) {
      case 'outbid':
        return {
          borderColor: Colors.danger,
          icon: 'trending-down',
          iconColor: Colors.danger,
          bgColor: 'rgba(239, 68, 68, 0.12)',
        };
      case 'winning':
      case 'auction_won':
        return {
          borderColor: Colors.success,
          icon: 'trophy',
          iconColor: Colors.success,
          bgColor: 'rgba(16, 185, 129, 0.12)',
        };
      case 'payment':
        return {
          borderColor: Colors.gold,
          icon: 'shield-checkmark',
          iconColor: Colors.gold,
          bgColor: 'rgba(251, 191, 36, 0.12)',
        };
      case 'shipping':
        return {
          borderColor: Colors.info,
          icon: 'cube',
          iconColor: Colors.info,
          bgColor: 'rgba(56, 189, 248, 0.12)',
        };
      default:
        return {
          borderColor: Colors.primary,
          icon: 'notifications',
          iconColor: Colors.primaryLight,
          bgColor: 'rgba(99, 102, 241, 0.12)',
        };
    }
  };

  const details = getNotifDetails();

  return (
    <Animated.View
      style={[
        styles.container,
        {
          transform: [{ translateY: slideAnim }],
          borderColor: details.borderColor,
        },
      ]}
    >
      <TouchableOpacity
        style={styles.inner}
        activeOpacity={0.9}
        onPress={() => {
          if (onPressNotification) onPressNotification(currentNotif);
          dismiss();
        }}
      >
        <View style={[styles.iconWrap, { backgroundColor: details.bgColor }]}>
          <Ionicons name={details.icon} size={18} color={details.iconColor} />
        </View>

        <View style={styles.textWrap}>
          <Text style={styles.title}>{currentNotif.title}</Text>
          <Text style={styles.message} numberOfLines={2}>
            {currentNotif.message}
          </Text>
        </View>

        <TouchableOpacity style={styles.closeBtn} onPress={dismiss} activeOpacity={0.7}>
          <Ionicons name="close" size={16} color={Colors.textMuted} />
        </TouchableOpacity>
      </TouchableOpacity>
    </Animated.View>
  );
};

const styles = StyleSheet.create({
  container: {
    position: 'absolute',
    top: 10,
    left: 12,
    right: 12,
    zIndex: 9999,
    backgroundColor: Colors.surface,
    borderRadius: 14,
    borderWidth: 1.5,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.45,
    shadowRadius: 12,
    elevation: 10,
  },
  inner: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 12,
  },
  iconWrap: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 10,
  },
  textWrap: {
    flex: 1,
    marginRight: 10,
  },
  title: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 2,
    letterSpacing: -0.2,
  },
  message: {
    fontSize: 11,
    color: Colors.textMuted,
    lineHeight: 16,
  },
  closeBtn: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: Colors.surfaceLight,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: Colors.borderSubtle,
  },
});
